FROM debian:12-slim
RUN apt-get update && apt-get install -y --no-install-recommends \
      ca-certificates curl jq git libicu72 liblttng-ust1 php-cli \
 && rm -rf /var/lib/apt/lists/*
RUN useradd -m runner
WORKDIR /home/runner
USER runner
RUN curl -fsSL -o r.tar.gz https://github.com/actions/runner/releases/download/v2.337.0/actions-runner-linux-x64-2.337.0.tar.gz \
 && tar xzf r.tar.gz && rm r.tar.gz
COPY --chmod=0755 entrypoint.sh /usr/local/bin/entrypoint.sh
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
