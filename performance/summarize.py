#!/usr/bin/env python
"""Print a compact table of one or more k6 result files: python performance/summarize.py performance/results/*-before.json"""
import json
import sys

for path in sys.argv[1:]:
    try:
        d = json.load(open(path))
    except Exception as e:  # noqa: BLE001
        print(f"{path}: {e}")
        continue
    print(f"== {d['scenario']} [{d['profile']}] vus_max={d.get('vus_max')} reqs={d['http_reqs']} iterations={d['iterations']} failed={round((d['http_req_failed_rate'] or 0) * 100, 2)}% 5xx={d['app_errors']} 429={d['rate_limited_429']}")
    rows = sorted(d['endpoints'].items(), key=lambda x: -((x[1] or {}).get('p95') or 0))
    print(f"   {'endpoint':32s} {'med':>8s} {'p95':>8s} {'p99':>8s} {'max':>8s} {'n':>6s}")
    for k, v in rows:
        if not v:
            continue
        print(f"   {k:32s} {v['med']!s:>8s} {v['p95']!s:>8s} {v['p99']!s:>8s} {v['max']!s:>8s} {v.get('count')!s:>6s}")
