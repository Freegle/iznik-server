# iznik-server

[![CircleCI](https://circleci.com/gh/Freegle/iznik-server.svg?style=svg)](<LINK>)
[![Coverage Status](https://coveralls.io/repos/github/Freegle/iznik-server/badge.svg?branch=master)](https://coveralls.io/github/Freegle/iznik-server?branch=master)

Iznik is a platform for online reuse of unwanted items.  This is the server half.  

There is a Docker Compose development environment which can be used to run a complete standalone system; see [FreegleDocker](https://github.com/Freegle/FreegleDocker).

The development has been funded by Freegle for use in the UK,
but it is an open source platform which can be used or adapted by others.

**It would be very lovely if you sponsored us.**

[:heart: Sponsor](https://github.com/sponsors/Freegle)

We welcome potential developers with open arms.  Have  a look at the wiki section.

## Docker

There's a basic Dockerfile, aimed for use in larger Docker Compose environments.  You can build:

`docker build -t iznik-server ./`

...then

`docker build -t iznik-server ./`

License
=======

This code is licensed under the GPL v2 (see LICENSE file).  If you intend to use it, Freegle would be interested to
hear about it; you can mail <geeks@ilovefreegle.org>.

Freegle's own use of this code includes a database of UK locations which is derived in part from OpenStreetMap data, and
is therefore subject to the Open Database License.  You can request a copy of this data by mailing 
<geeks@ilovefreegle.org>.

