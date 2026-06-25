FROM node:22-bookworm

RUN apt-get update && apt-get install -y \
  git \
  curl \
  jq \
  bubblewrap \
  && rm -rf /var/lib/apt/lists/*

# Codex uses bubblewrap for its app-server/sandbox layer. Docker blocks unprivileged
# user namespaces unless bwrap is setuid (see OpenAI Codex Docker docs).
RUN chmod u+s /usr/bin/bwrap

RUN curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg \
  | dd of=/usr/share/keyrings/githubcli-archive-keyring.gpg \
  && echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" \
  | tee /etc/apt/sources.list.d/github-cli.list > /dev/null \
  && apt-get update && apt-get install -y gh \
  && rm -rf /var/lib/apt/lists/*

ARG AGENT_UID=1000
ARG AGENT_GID=1000

RUN groupmod -o -g $AGENT_GID node && usermod -o -u $AGENT_UID -g $AGENT_GID -d /home/agent -m -l agent node

# Codex CLI — global install (implementer); setuid any bundled bwrap copy too.
RUN npm install -g @openai/codex \
  && find "$(npm root -g)/@openai/codex" -name bwrap -type f -exec chmod u+s {} + 2>/dev/null || true

USER ${AGENT_UID}:${AGENT_GID}

# Cursor CLI — user install (reviewer); self-contained binary, no IDE required
RUN curl -fsSL https://cursor.com/install | bash

ENV PATH="/home/agent/.local/bin:${PATH}"

WORKDIR /home/agent

ENTRYPOINT ["sleep", "infinity"]
