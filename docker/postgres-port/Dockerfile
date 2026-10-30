FROM openemr/openemr:flex@sha256:dfa6c36046d9493656fc98706f2647d6705e5522f15d8a0e78224dff491cf50b

RUN apk add --no-cache php85-pdo_pgsql

WORKDIR /workspace
ENTRYPOINT ["sh", "/workspace/docker/postgres-port/run-tests.sh"]
