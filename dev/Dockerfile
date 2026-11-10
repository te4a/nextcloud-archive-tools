FROM nextcloud:34.0.5-apache@sha256:d8ad0b77c863611b9fab6e72a82a5d35fbcb218b660f9f20fec9941a50806281

# Full official build includes the RAR decoders omitted by Debian's DFSG build.
# Extends the existing Nextcloud/PHP image; no separate archive service is needed.
ARG SEVENZIP_URL=https://github.com/ip7z/7zip/releases/download/26.06/7z2606-linux-x64.tar.xz
ARG SEVENZIP_SHA256=2b539cf067b2ad48e1baa5093dbaa8818b73542d1222a0ffde9dc5f2922ea62e
# util-linux (prlimit) is inherited from this pinned Nextcloud image.
RUN test -x /usr/bin/prlimit \
    && test "$(dpkg --print-architecture)" = amd64 \
    && mkdir /tmp/archive-tools-7zip-build \
    && curl --fail --location --retry 3 "$SEVENZIP_URL" --output /tmp/archive-tools-7zip-build/archive.tar.xz \
    && echo "$SEVENZIP_SHA256  /tmp/archive-tools-7zip-build/archive.tar.xz" | sha256sum --check --strict \
    && tar -xJf /tmp/archive-tools-7zip-build/archive.tar.xz -C /tmp/archive-tools-7zip-build 7zz \
    && install -m 0755 /tmp/archive-tools-7zip-build/7zz /usr/local/bin/7zz \
    && rm /tmp/archive-tools-7zip-build/archive.tar.xz /tmp/archive-tools-7zip-build/7zz \
    && rmdir /tmp/archive-tools-7zip-build
