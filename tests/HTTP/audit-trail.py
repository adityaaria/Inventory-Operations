#!/usr/bin/env python3
"""Local seeded audit UI role checks; reads audit history, login writes normal auth audit."""
import urllib.request,urllib.parse,urllib.error,http.cookiejar,re,json
from pathlib import Path
checks=[]
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args): return None
for role,email in [('Admin','admin@example.test'),('Sales','sales1@example.test'),('WarehouseStaff','warehouse1@example.test')]:
    jar=http.cookiejar.CookieJar(); opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar),NoRedirect())
    def request(path,data=None):
        try: response=opener.open('http://localhost:8080'+path,None if data is None else urllib.parse.urlencode(data).encode(),timeout=15)
        except urllib.error.HTTPError as error: response=error
        return response.code,response.read().decode()
    _,body=request('/login'); token=re.search(r'name="csrf_token" value="([^\"]+)"',body).group(1)
    assert request('/login',{'email':email,'password':'password','csrf_token':token})[0]==302
    code,body=request('/audit-trail'); assert code==(200 if role=='Admin' else 403),(role,code)
    checks.append(role+' HTTP access correct')
    if role=='Admin':
        assert 'Audit Trail' in body and 'pagination' in body and 'status-badge' in body
        assert request('/audit-trail?status=invented')[0]==422
        code,empty=request('/audit-trail?action=nonexistent-audit-action')
        assert code==200 and 'No audit records match' in empty
        checks.append('Admin filters badges pagination empty state and invalid input')
    else:
        assert '/audit-trail' not in request('/dashboard')[1]
output=Path('docs/testing/audit-trail-2026-10-07/http.json'); output.parent.mkdir(parents=True,exist_ok=True)
output.write_text(json.dumps({'passed':True,'checks':checks},indent=2)+'\n')
print(json.dumps({'passed':True,'checks':checks}))
