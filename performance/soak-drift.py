#!/usr/bin/env python
"""Soak drift analysis: split the docker-stats timeline into thirds and compare memory / CPU (leak detection).
   python performance/soak-drift.py performance/results/full-workflow-soak-after.stats.csv"""
import csv
import sys
from collections import defaultdict

rows = list(csv.DictReader(open(sys.argv[1])))
by = defaultdict(list)
for r in rows:
    try:
        by[r['container'].split('-')[-1]].append((float(r['cpu_pct'].rstrip('%')), r['mem_used'].split('/')[0].strip()))
    except Exception:
        pass


def mib(s):
    n = float(s.replace('MiB', '').replace('GiB', '').replace('KiB', ''))
    return n * 1024 if 'GiB' in s else (n / 1024 if 'KiB' in s else n)


print(f"{'container':12s} {'samples':>7s} | {'mem first third':>15s} {'mem last third':>14s} {'delta':>8s} | {'cpu first':>9s} {'cpu last':>8s}")
for c, v in sorted(by.items()):
    n = len(v)
    if n < 6:
        continue
    t = n // 3
    m1 = sum(mib(x[1]) for x in v[:t]) / t
    m3 = sum(mib(x[1]) for x in v[-t:]) / t
    c1 = sum(x[0] for x in v[:t]) / t
    c3 = sum(x[0] for x in v[-t:]) / t
    print(f"{c:12s} {n:>7d} | {m1:>12.0f} MB {m3:>11.0f} MB {m3 - m1:>+7.0f} MB | {c1:>8.1f}% {c3:>7.1f}%")
