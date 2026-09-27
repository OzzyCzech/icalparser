"""Expand recurrence rules with python-dateutil for tests/Differential/dateutil.phpt.

Input (stdin): JSON list of {"rule": "FREQ=...", "start": "2026-01-05T09:00:00", "tz": "Europe/Prague", "take": 200}
Output (stdout): JSON list of lists of Unix timestamps, null when dateutil fails on the rule
or needs more than a second.
"""
import json
import signal
import sys
from datetime import datetime

from dateutil import rrule, tz

def timeout(signum, frame):
    raise TimeoutError()


signal.signal(signal.SIGALRM, timeout)

result = []
for case in json.load(sys.stdin):
    zone = tz.gettz(case["tz"])
    start = datetime.fromisoformat(case["start"]).replace(tzinfo=zone)
    timestamps = []
    signal.alarm(1)  # dateutil searches impossible rules until year 9999
    try:
        for occurrence in rrule.rrulestr(case["rule"], dtstart=start):
            # RFC 5545, section 3.3.5: a nonexistent time uses the offset before the gap,
            # which is what resolve_imaginary() does; a recurrence set has no duplicates
            timestamp = int(tz.resolve_imaginary(occurrence).timestamp())
            if timestamps and timestamps[-1] == timestamp:
                continue
            timestamps.append(timestamp)
            if len(timestamps) >= case["take"]:
                break
    except Exception:  # dateutil fails on some valid rules (e.g. BYWEEKNO=53) or is too slow
        timestamps = None
    finally:
        signal.alarm(0)
    result.append(timestamps)
json.dump(result, sys.stdout)
