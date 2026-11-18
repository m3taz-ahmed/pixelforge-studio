#!/usr/bin/env bash
set -u
cd /workspaces/pixelforge-studio
mkdir -p storage/logs
pgrep -f 'artisan serve' >/dev/null || nohup php artisan serve --host=0.0.0.0 --port=8000 > storage/logs/serve.log 2>&1 &
pgrep -f 'artisan queue:work' >/dev/null || nohup php artisan queue:work --daemon > storage/logs/queue.log 2>&1 &
pgrep -f 'artisan schedule:work' >/dev/null || nohup php artisan schedule:work > storage/logs/schedule.log 2>&1 &
