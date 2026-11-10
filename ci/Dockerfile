ARG NEXTCLOUD_IMAGE=nextcloud:34.0.5-apache@sha256:d8ad0b77c863611b9fab6e72a82a5d35fbcb218b660f9f20fec9941a50806281
FROM ${NEXTCLOUD_IMAGE}

# Same full RAR-capable decoder as the development image; no archiver service.
ARG SEVENZIP_URL=https://github.com/ip7z/7zip/releases/download/26.06/7z2606-linux-x64.tar.xz
ARG SEVENZIP_SHA256=2b539cf067b2ad48e1baa5093dbaa8818b73542d1222a0ffde9dc5f2922ea62e
RUN test -x /usr/bin/prlimit \
    && test "$(dpkg --print-architecture)" = amd64 \
    && mkdir /tmp/archive-tools-7zip-ci \
    && curl --fail --location --retry 3 "$SEVENZIP_URL" -o /tmp/archive-tools-7zip-ci/source.tar.xz \
    && echo "$SEVENZIP_SHA256  /tmp/archive-tools-7zip-ci/source.tar.xz" | sha256sum --check --strict \
    && tar -xJf /tmp/archive-tools-7zip-ci/source.tar.xz -C /tmp/archive-tools-7zip-ci 7zz \
    && install -m 0755 /tmp/archive-tools-7zip-ci/7zz /usr/local/bin/7zz \
    && rm /tmp/archive-tools-7zip-ci/source.tar.xz /tmp/archive-tools-7zip-ci/7zz \
    && rmdir /tmp/archive-tools-7zip-ci
