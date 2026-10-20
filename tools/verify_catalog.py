#!/usr/bin/env python3
"""Cross-checks catalog coordinates against OpenStreetMap Nominatim (1 req/s, per usage policy).
Writes osm_ref + verified flag into catalog.json for places within TOLERANCE_M of an OSM result."""
import json, math, subprocess, time, urllib.parse, datetime
TOLERANCE_M = 400
UA = 'RoamWise-catalog-check/0.1 (contact: hashy2601@gmail.com)'
def hav(a,b,c,d):
    r=6371000; p=math.radians
    x=math.sin(p(c-a)/2)**2+math.cos(p(a))*math.cos(p(c))*math.sin(p(d-b)/2)**2
    return 2*r*math.asin(math.sqrt(x))
cat=json.load(open('database/data/catalog.json'))
today=datetime.date.today().isoformat()
report=[]
for d in cat:
    for p in d['places']:
        q=urllib.parse.quote(f"{p['name']}, {d['name']}")
        url=f"https://nominatim.openstreetmap.org/search?q={q}&format=json&limit=3"
        try:
            out=subprocess.run(['curl','-sS','-m','20','-A',UA,url],capture_output=True,text=True).stdout
            res=json.loads(out)
        except Exception as e:
            res=[]
        best=None
        for r in res:
            dist=hav(p['latitude'],p['longitude'],float(r['lat']),float(r['lon']))
            if best is None or dist<best[0]: best=(dist,r)
        if best and best[0]<=TOLERANCE_M:
            p['osm_ref']=f"{best[1]['osm_type']}/{best[1]['osm_id']}"; p['verified_on']=today
            report.append((p['name'],'OK',round(best[0])))
        else:
            report.append((p['name'],'UNVERIFIED',round(best[0]) if best else None, best[1]['display_name'][:60] if best else None))
        time.sleep(1.2)
json.dump(cat,open('database/data/catalog.json','w'),indent=2,ensure_ascii=False)
for r in report: print(r)
