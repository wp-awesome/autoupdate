# autoupdate

A compatibility gate for containerised WordPress projects, so base-image
upgrades can merge themselves without merging something that cannot run.

## Use it

```yaml
# .github/workflows/upgrade-gate.yml
name: upgrade gate
on: [pull_request]

jobs:
  compatibility:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: wp-awesome/autoupdate@v1
```

That is the whole configuration. The gate finds your `FROM wordpress:…` pins
and your plugins and themes by itself.

It assumes only that plugins live under `wp-content/plugins` or
`www/wp-content/plugins` and that the runtime is pinned as
`FROM wordpress:<wp>-php<php>-fpm`. Nothing else about your project is this
repository's business — how you build, how you test, how you deploy, and what
"passing" means all belong to you.

Pin `@v1`, not `@main`. A bad merge here would otherwise be a bad merge in
every project at once.

## What it decides

Exit code is the contract:

| code | meaning |
|---|---|
| 0 | no declared minimum is violated |
| 1 | a plugin or theme requires a newer WordPress or PHP than the pin provides |
| 2 | the repository could not be understood — **not** a pass |

Code 2 matters as much as code 1. A gate that cannot parse a project and
reports success certifies every future upgrade for it, silently and forever.
So it refuses when it finds no pin, finds no plugins or themes, or finds pins
that disagree with each other — the last because a project may pin the same
image in several Dockerfiles, and a bump that moves one leaves a stack whose
parts were built from different WordPress versions.

## What it cannot decide

Plugin headers declare **minimums**. Nothing in the format expresses a maximum.

So this can never fail a PHP upgrade: PHP 8.4, 8.5 and 9.0 all satisfy
"requires 7.4" trivially. A green result means "no declared minimum is
violated". It does not mean "works".

Evidence that an upgrade works comes from two places, both yours: the image
build, which fails loudly when an extension will not compile, and tests run
against a **booted** site. A suite that only parses files will pass while a
booted site fatals — that is not hypothetical, it is the common case.

`Tested up to` is ignored deliberately. It is an author's statement about what
they last checked, not a constraint, and honouring it would pin every site to
the release cadence of its least-maintained plugin.

## Scheduling upgrades

`templates/dependabot.yml` is a starting point. It uses Dependabot rather than
a scheduled workflow on purpose: GitHub disables `schedule` triggers after 60
days of repository inactivity and does not re-enable them, so updates would
stop on exactly the quietest projects. Dependabot is exempt from that rule.

To suspend updates for a project, delete its `dependabot.yml` and close any
open pull requests in the same commit — left open, they merge as soon as their
checks finish.

## Self-hosted runner

`runner/` builds a GitHub Actions runner for hosts that cannot run it natively,
such as Synology DSM. Entirely optional, and unrelated to the gate.
