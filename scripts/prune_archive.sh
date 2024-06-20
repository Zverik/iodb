#!/bin/bash
find "$(dirname "$0")/download" -name 'iodb*' ! -name '*01.*' ! -name '*-1303*' ! -name "*-$(date +%y%m)*" ! -name '*latest*' -delete
