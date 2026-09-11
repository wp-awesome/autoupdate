# Self-hosted runner (Synology DSM)

DSM cannot run the GitHub runner natively — it lacks the ICU and OpenSSL
libraries the .NET-based runner links against — so it runs in a container.
That is the supported shape on Synology, not a workaround.

Built from the official runner tarball rather than a third-party runner image,
because this executes repository code on a NAS that also carries several
clients' preview environments.

## Deliberately no Docker socket

The compatibility gate needs only PHP. Mounting the host socket would give
every job root over every other tenant on the NAS, so build access is a
separate decision with a separate blast radius — not a default.

## Ephemeral, and why the token is per-job

Registration tokens expire in an hour, so a long-lived runner cannot hold one.
The entrypoint mints a fresh one per job from a stored fine-grained PAT
(organization permission: Self-hosted runners, read and write — a *fine-grained*
token whose resource owner is the org, which is the usual thing to get wrong).

`--ephemeral` means the runner deregisters after a single job, so one
repository's job cannot leave state behind for the next repository's job.

A restart or crash mid-life leaves `.runner` behind and `config.sh` then
refuses with "already configured", which reads like a credential failure and is
not. The entrypoint clears it first.

## Run

    docker run -d --name gh-runner-wp-awesome --restart unless-stopped \
      -e ORG=wp-awesome -e RUNNER_NAME=nexapulse-dsm \
      -e RUNNER_LABELS=self-hosted,linux,x64,nexapulse \
      -v /volume1/docker/gh-runner/token:/run/secrets/gh-token:ro \
      gh-runner-dsm:v2
