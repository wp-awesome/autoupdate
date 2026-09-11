#!/bin/bash
# Mint a fresh registration token per job.
#
# Registration tokens expire in an hour, so a long-lived runner cannot hold
# one. --ephemeral means the runner deregisters after a single job, which is
# what keeps one repository's job from leaving state behind for the next
# repository's job on a NAS shared by several clients.
set -uo pipefail
: "${ORG:?}" "${RUNNER_NAME:?}" "${RUNNER_LABELS:?}"

while true; do
  tok=$(curl -sS -X POST \
        -H "Authorization: Bearer $(cat /run/secrets/gh-token)" \
        -H "Accept: application/vnd.github+json" \
        "https://api.github.com/orgs/${ORG}/actions/runners/registration-token" | jq -r .token)

  if [ -z "$tok" ] || [ "$tok" = "null" ]; then
    echo "could not mint a registration token; retrying in 60s" >&2
    sleep 60
    continue
  fi

  # An ephemeral runner normally deregisters itself after its job. A restart
  # or a crash mid-life leaves .runner behind, and config.sh then refuses with
  # "already configured" - which looks like a credential problem and is not.
  if [ -f .runner ]; then
    ./config.sh remove --token "$tok" >/dev/null 2>&1 || rm -f .runner .credentials .credentials_rsaparams
  fi

  ./config.sh --url "https://github.com/${ORG}" --token "$tok" \
      --name "$RUNNER_NAME" --labels "$RUNNER_LABELS" \
      --unattended --replace --ephemeral || { sleep 30; continue; }

  ./run.sh
  sleep 5
done
