#!/bin/bash
# Build here and ship, the same path CI takes.
set -e
cd /root/code/smm-reseller-hub

echo "=== building ==="
npm run build 2>&1 | tail -3

echo
echo "=== shipping ==="
tar -czf /tmp/build.tar.gz -C public build
scp -q -i /root/.ssh/vps_deploy /tmp/build.tar.gz root@186.240.153.29:/tmp/build.tar.gz
rm -f /tmp/build.tar.gz

echo
echo "=== deploying ==="
ssh -i /root/.ssh/vps_deploy root@186.240.153.29 \
    "BRANCH=master bash /root/deploy.sh" 2>&1 | tail -22
