#!/bin/sh
CHECK_PATH=/var/www/projects/oro-mono/master
rm -rf logs;
mkdir logs;
ls ${CHECK_PATH}/package \
| grep -v "\-demo" | grep -v "demo-" | grep -v "test-" | grep -v "german-" \
| parallel -j 4  "./vendor/bin/phpstan analyze -c config.neon ${CHECK_PATH}/package/{} --autoload-file=${CHECK_PATH}/application/commerce-crm-ee/app/autoload.php > logs/{}.log"
cd logs
grep -l "\[OK\] No errors" * | xargs rm
