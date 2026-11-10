# RAR reference fixtures

The unchanged uuencoded test assets come from the official
[libarchive repository](https://github.com/libarchive/libarchive), pinned at
commit `4ec9c0245c1d92d981800522168a1203d98b1236`.

- [test_read_format_rar_subblock.rar.uu](https://github.com/libarchive/libarchive/blob/4ec9c0245c1d92d981800522168a1203d98b1236/libarchive/test/test_read_format_rar_subblock.rar.uu)
  is a genuine RAR 3/4-format archive containing the regular member
  `test.txt`, with `test text document` followed by CRLF. The 20 expected bytes
  are specified independently by `test_read_format_rar_subblock` in the upstream
  [RAR tests](https://github.com/libarchive/libarchive/blob/4ec9c0245c1d92d981800522168a1203d98b1236/libarchive/test/test_read_format_rar.c).
- [test_read_format_rar5_stored.rar.uu](https://github.com/libarchive/libarchive/blob/4ec9c0245c1d92d981800522168a1203d98b1236/libarchive/test/test_read_format_rar5_stored.rar.uu)
  is a genuine RAR 5 archive containing `helloworld.txt`, with the bytes
  `hello libarchive test suite!` followed by LF. Its expected contents are
  specified by the upstream
  [RAR 5 tests](https://github.com/libarchive/libarchive/blob/4ec9c0245c1d92d981800522168a1203d98b1236/libarchive/test/test_read_format_rar5.c).

`format-fixtures.php` decodes these files for the local tests. It does not use
the application reader to derive expected data, and no RAR encoder is needed.
The 7z fixtures are generated locally from public test strings; their password
`format-test` is public fixture data.

Multipart isolation tests additionally use these unchanged assets from the
same pinned commit and under the same license:

- `test_rar_multivolume_single_file.part1.rar.uu`, `part2.rar.uu`, and
  `part3.rar.uu` form the set referenced by `test_read_format_rar_multivolume_stored_file`
  in the upstream RAR tests. The complete stored member is independently
  specified as `LibarchiveAddingTest.html`, 20,111 bytes.
- `test_read_format_rar5_multiarchive.part01.rar.uu` and `part02.rar.uu`
  are the first two pieces referenced by the upstream RAR 5 multipart tests.

The tests place the following pieces beside the authorized first piece,
then verify that native header checks reject multipart inputs and that a
decoder operating through a private opaque alias cannot follow those neighbors.

The original distribution's BSD-style license is retained verbatim in
`LIBARCHIVE-COPYING`. The associated upstream test copyright notices are:

```
Copyright (c) 2003-2007 Tim Kientzle
Copyright (c) 2011 Andres Mejia
Copyright (c) 2011-2012 Michihiro NAKAJIMA
Copyright (c) 2026 Eduard Zateev
Copyright (c) 2018 Grzegorz Antoniak
All rights reserved.
```

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions
are met:
1. Redistributions of source code must retain the above copyright
   notice, this list of conditions and the following disclaimer.
2. Redistributions in binary form must reproduce the above copyright
   notice, this list of conditions and the following disclaimer in the
   documentation and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE AUTHOR(S) ``AS IS'' AND ANY EXPRESS OR
IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES
OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
IN NO EVENT SHALL THE AUTHOR(S) BE LIABLE FOR ANY DIRECT, INDIRECT,
INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT
NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
(INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF
THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
