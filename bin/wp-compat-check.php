#!/usr/bin/env php
<?php

/**
 * Compatibility gate for a containerised WordPress upgrade.
 *
 * Answers one narrow question: does the WordPress and PHP version in the
 * pinned base image satisfy every minimum DECLARED by the plugins and themes
 * in this repository?
 *
 * WHAT THIS CANNOT DO, and why saying so matters.
 *
 * Plugins declare `Requires PHP` and `Requires at least`. Both are MINIMUMS.
 * Nothing in the WordPress plugin header format expresses a maximum, so this
 * check can never fail a PHP upgrade: PHP 8.4, 8.5, 9.0 all satisfy
 * "requires 7.4" trivially. A green result here means "no declared minimum is
 * violated". It does NOT mean "works".
 *
 * All real protection against an upgrade that breaks things comes from two
 * places downstream: the image build, which fails loudly when an extension
 * will not compile, and a test run against a BOOTED site. Treating this gate
 * as the safety net is the mistake it is designed to make visible.
 *
 * `Tested up to` is deliberately ignored. It is an author's statement about
 * what they last checked, not a constraint, and blocking on it would pin every
 * site to the release cadence of its least-maintained plugin.
 *
 * Usage:
 *   wp-compat-check.php --root=<repo root> [--image=wordpress:7.1-php8.4-fpm]
 *
 * With no --image, the pin is auto-detected from the Dockerfiles.
 * Exit 0 = satisfied. Exit 1 = a declared minimum is violated. Exit 2 = the
 * repository could not be understood, which is NOT a pass.
 */

declare(strict_types=1);

const EXIT_OK          = 0;
const EXIT_VIOLATION   = 1;
const EXIT_UNDETECTED  = 2;

/** Parse `--key=value` arguments into a map. */
function parse_args(array $argv): array {
	$out = [];
	foreach (array_slice($argv, 1) as $arg) {
		if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) {
			$out[$m[1]] = $m[2];
		}
	}
	return $out;
}

/**
 * Every `FROM wordpress:<wp>-php<php>-fpm` pin in the tree.
 *
 * Returned as a list rather than a single value on purpose: a project can pin
 * the same image in several Dockerfiles — VEO pins it three times across two
 * files — and they must move together. A bump that updates one and not the
 * others produces a stack whose parts disagree, which is far harder to
 * diagnose than a failure to upgrade.
 */
function detect_pins(string $root): array {
	$pins = [];
	$it = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
			static fn($f) => $f->isDir()
				? ! in_array($f->getFilename(), ['.git', 'node_modules', 'vendor'], true)
				: str_contains($f->getFilename(), 'Dockerfile')
		)
	);

	foreach ($it as $file) {
		$contents = file_get_contents($file->getPathname());
		if (false === $contents) {
			continue;
		}
		if (preg_match_all(
			'/^FROM\s+wordpress:(?<wp>[0-9][0-9.]*)-php(?<php>[0-9]+\.[0-9]+)-fpm(?<digest>@sha256:[a-f0-9]{64})?/mi',
			$contents,
			$matches,
			PREG_SET_ORDER
		)) {
			foreach ($matches as $m) {
				$pins[] = [
					'file'   => str_replace($root . '/', '', $file->getPathname()),
					'wp'     => $m['wp'],
					'php'    => $m['php'],
					'digest' => $m['digest'] ?? '',
				];
			}
		}
	}

	return $pins;
}

/** Read one plugin/theme header value, scanning only the first 8KB as WordPress does. */
function read_header(string $file, string $name): ?string {
	$fh = fopen($file, 'r');
	if (false === $fh) {
		return null;
	}
	$head = fread($fh, 8192);
	fclose($fh);
	if (false === $head) {
		return null;
	}
	// WordPress tolerates arbitrary whitespace and an optional leading `*`.
	if (preg_match('/^[ \t\/*#@]*' . preg_quote($name, '/') . ':(.*)$/mi', $head, $m)) {
		$v = trim(preg_replace('/\s*(?:\*\/|\?>).*/', '', $m[1]) ?? '');
		return '' === $v ? null : $v;
	}
	return null;
}

/**
 * Declared requirements of every plugin and theme found in the tree.
 *
 * Plugin entry points are not named predictably, so every top-level PHP file
 * in each plugin directory is examined and the one carrying a `Plugin Name`
 * header wins — the same rule WordPress itself applies.
 */
function collect_components(string $root): array {
	$found = [];

	foreach (['wp-content', 'www/wp-content'] as $rel) {
		$base = $root . '/' . $rel;
		if (! is_dir($base)) {
			continue;
		}

		foreach (['plugins' => 'Plugin Name', 'themes' => 'Theme Name'] as $dir => $marker) {
			$path = $base . '/' . $dir;
			if (! is_dir($path)) {
				continue;
			}

			foreach (new DirectoryIterator($path) as $entry) {
				if ($entry->isDot() || ! $entry->isDir()) {
					continue;
				}

				$candidates = 'themes' === $dir
					? [$entry->getPathname() . '/style.css']
					: (glob($entry->getPathname() . '/*.php') ?: []);

				foreach ($candidates as $candidate) {
					if (null === read_header($candidate, $marker)) {
						continue;
					}
					$found[] = [
						'type' => rtrim($dir, 's'),
						'slug' => $entry->getFilename(),
						'name' => read_header($candidate, $marker),
						'php'  => read_header($candidate, 'Requires PHP'),
						'wp'   => read_header($candidate, 'Requires at least'),
					];
					break;
				}
			}
		}
	}

	usort($found, static fn($a, $b) => [$a['type'], $a['slug']] <=> [$b['type'], $b['slug']]);
	return $found;
}

// ---------------------------------------------------------------------------

$args = parse_args($argv);
$root = rtrim($args['root'] ?? getcwd(), '/');

if (! is_dir($root)) {
	fwrite(STDERR, "not a directory: $root\n");
	exit(EXIT_UNDETECTED);
}

$pins = detect_pins($root);

if (isset($args['image'])) {
	if (! preg_match('/wordpress:(?<wp>[0-9][0-9.]*)-php(?<php>[0-9]+\.[0-9]+)-fpm/', $args['image'], $m)) {
		fwrite(STDERR, "could not parse --image: {$args['image']}\n");
		exit(EXIT_UNDETECTED);
	}
	$target = ['wp' => $m['wp'], 'php' => $m['php']];
	$source = "--image {$args['image']}";
} else {
	if ([] === $pins) {
		fwrite(STDERR, "no `FROM wordpress:<wp>-php<php>-fpm` pin found under $root\n");
		fwrite(STDERR, "refusing to report success on a repository this check does not understand\n");
		exit(EXIT_UNDETECTED);
	}
	$distinct = array_unique(array_map(static fn($p) => $p['wp'] . '|' . $p['php'], $pins));
	if (count($distinct) > 1) {
		fwrite(STDERR, "pins disagree across Dockerfiles; they must move together:\n");
		foreach ($pins as $p) {
			fwrite(STDERR, sprintf("  %-32s wordpress %s on php %s\n", $p['file'], $p['wp'], $p['php']));
		}
		exit(EXIT_UNDETECTED);
	}
	$target = ['wp' => $pins[0]['wp'], 'php' => $pins[0]['php']];
	$source = count($pins) . ' pin(s) in ' . implode(', ', array_unique(array_column($pins, 'file')));
}

$components = collect_components($root);

if ([] === $components) {
	fwrite(STDERR, "no plugins or themes found under $root\n");
	fwrite(STDERR, "a repository with nothing to check is not a repository that passed\n");
	exit(EXIT_UNDETECTED);
}

printf("target: WordPress %s on PHP %s  (%s)\n", $target['wp'], $target['php'], $source);
printf("checking %d components\n\n", count($components));

$violations = [];
foreach ($components as $c) {
	$notes = [];
	if (null !== $c['php'] && version_compare($target['php'], $c['php'], '<')) {
		$notes[] = "needs PHP >= {$c['php']}";
	}
	if (null !== $c['wp'] && version_compare($target['wp'], $c['wp'], '<')) {
		$notes[] = "needs WP >= {$c['wp']}";
	}
	if ([] !== $notes) {
		$violations[] = $c['slug'] . ': ' . implode(', ', $notes);
	}
	printf(
		"  %-7s %-34s php>=%-7s wp>=%-7s %s\n",
		$c['type'],
		substr($c['slug'], 0, 34),
		$c['php'] ?? '-',
		$c['wp'] ?? '-',
		[] === $notes ? 'ok' : 'BLOCKS: ' . implode(', ', $notes)
	);
}

echo "\n";

if ([] !== $violations) {
	echo "BLOCKED — declared minimums are not satisfied:\n";
	foreach ($violations as $v) {
		echo "  $v\n";
	}
	exit(EXIT_VIOLATION);
}

echo "No declared minimum is violated.\n";
echo "This is NOT evidence the upgrade works: no plugin declares a maximum PHP\n";
echo "or WordPress version, so this check cannot fail a PHP upgrade. Whether it\n";
echo "runs is decided by the image build and by tests against a booted site.\n";
exit(EXIT_OK);
