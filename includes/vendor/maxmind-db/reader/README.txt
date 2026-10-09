MaxMind DB Reader for PHP — bundled copy

Package:  maxmind-db/reader v1.13.1 (pure PHP, no C extension needed)
Source:   https://github.com/maxmind/MaxMind-DB-Reader-php/tree/v1.13.1
Archive:  https://codeload.github.com/maxmind/MaxMind-DB-Reader-php/tar.gz/refs/tags/v1.13.1
          sha256 b4aa77cbd550f6e37f45c35611066403a174f84b489a408f7610c69de3e485b2
License:  Apache License 2.0 (see LICENSE in this folder)

Only src/MaxMind/Db/ and LICENSE are included, unchanged. The files are loaded
by IQU_Geo::load_library() (no Composer). If another plugin has already loaded
the MaxMind\Db\Reader classes, that copy is used instead.

Used to read the GeoLite2 Country database (2-letter country code only).
