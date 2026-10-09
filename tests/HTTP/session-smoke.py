#!/usr/bin/env python3
"""Non-destructive real HTTP session checks. Needs a running seeded app; never prints cookies/tokens."""
import argparse, http.cookiejar, json, re, urllib.request, urllib.parse, urllib.error, time

parser = argparse.ArgumentParser()
parser.add_argument('--url', default='http://localhost:8080')
parser.add_argument('--short-timeouts', action='store_true', help='Target must use idle=4, absolute=10, rotation=1 seconds')
parser.add_argument('--pause-for-recreate', action='store_true')
args = parser.parse_args()
results = []

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *unused): return None

def client():
    jar = http.cookiejar.CookieJar()
    return jar, urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect())

def request(opener, path, data=None, headers=None):
    req = urllib.request.Request(args.url + path, data=urllib.parse.urlencode(data).encode() if data is not None else None, headers=headers or {})
    try: response = opener.open(req, timeout=15)
    except urllib.error.HTTPError as error: response = error
    return response.code, response.headers, response.read().decode()

def check(name, condition):
    assert condition, name
    results.append(name)

def token(body):
    return re.search(r'name="csrf_token" value="([a-f0-9]+)"', body).group(1)

def sid(jar):
    return next(c.value for c in jar if c.name == 'PHPSESSID')

def login(email):
    jar, opener = client()
    status, headers, body = request(opener, '/login')
    before, old_token = sid(jar), token(body)
    check('Login response forbids caching', 'no-store' in headers.get('Cache-Control', ''))
    status, headers, body = request(opener, '/login', {'email':email,'password':'password','csrf_token':old_token})
    check('Successful login rotates anonymous ID', status == 302 and sid(jar) != before)
    check('No companion role cookie', all(c.name != 'user_role' for c in jar))
    _, _, body = request(opener, '/dashboard')
    check('Successful login rotates CSRF token', token(body) != old_token)
    return jar, opener

jar, opener = client()
status, headers, _ = request(opener, '/dashboard')
check('Anonymous protected page redirects to login', status == 302 and headers.get('Location') == '/login')
status, headers, body = request(opener, '/api/products/BIS-0001/availability')
check('Anonymous API returns JSON 401', status == 401 and 'application/json' in headers.get('Content-Type','') and json.loads(body))
check('API errors forbid caching', 'no-store' in headers.get('Cache-Control',''))
jar, opener = client()
_, headers, body = request(opener, '/login')
check('Session cookie is HttpOnly / SameSite=Lax / Path=/', all(v in headers.get('Set-Cookie','') for v in ['HttpOnly','SameSite=Lax','path=/']))
check('Session cookie expires on browser close', 'expires=' not in headers.get('Set-Cookie','').lower())
status, _, _ = request(opener, '/login', {'email':'admin@example.test','password':'password','csrf_token':'wrong'})
check('Invalid login CSRF is denied', status == 403)
forged_jar, forged = client()
status, _, _ = request(forged, '/login', headers={'Cookie':'PHPSESSID=attackerchosenidentifier123456789'})
check('Unknown caller-supplied session ID is replaced', status == 200 and sid(forged_jar) != 'attackerchosenidentifier123456789')

jar, opener = login('admin@example.test')
status, _, body = request(opener, '/dashboard')
check('Authenticated dashboard succeeds', status == 200 and 'Role: Admin' in body)
status, headers, body = request(opener, '/api/products/BIS-0001/availability')
check('Authenticated API remains JSON 200', status == 200 and 'application/json' in headers.get('Content-Type','') and json.loads(body))
if args.pause_for_recreate:
    print('READY_FOR_RECREATE', flush=True)
    input()
    status, _, body = request(opener, '/dashboard')
    check('Session survives app container recreation', status == 200 and 'Role: Admin' in body)
if args.short_timeouts:
    previous = sid(jar)
    time.sleep(1.1)
    status, _, _ = request(opener, '/dashboard')
    check('Periodic HTTP rotation preserves authentication', status == 200 and sid(jar) != previous)
    _, replay = client()
    status, _, _ = request(replay, '/dashboard', headers={'Cookie':'PHPSESSID=' + previous})
    check('Previous ID cannot replay authentication after rotation', status == 302)
    time.sleep(4.1)
    status, headers, _ = request(opener, '/dashboard')
    check('Idle deadline redirects protected navigation to login', status == 302 and headers.get('Location') == '/login')
    status, _, _ = request(opener, '/categories/create', headers={'X-Requested-With':'fetch'})
    check('Expired modal request returns 401 instead of login HTML', status == 401)
    status, _, _ = request(opener, '/categories', {'csrf_token':'old-token','name':'must-not-save'}, headers={'X-Requested-With':'fetch'})
    check('Expired mutation returns 401 before persistence or CSRF processing', status == 401)
    jar, opener = login('admin@example.test')
    start = time.monotonic()
    deadline_reached = False
    while time.monotonic() - start < 11:
        time.sleep(0.8)
        status, headers, _ = request(opener, '/dashboard')
        if status == 302:
            deadline_reached = True
            break
        assert status == 200
    check('Active HTTP traffic cannot extend absolute lifetime', deadline_reached and time.monotonic() - start >= 8.5)
    jar, opener = login('admin@example.test')

_, _, body = request(opener, '/dashboard')
previous = sid(jar)
status, headers, _ = request(opener, '/logout', {'csrf_token':token(body)})
check('Logout redirects and rotates ID', status == 302 and headers.get('Location') == '/login' and sid(jar) != previous)
_, replay = client()
status, _, _ = request(replay, '/dashboard', headers={'Cookie':'PHPSESSID=' + previous})
check('Previous authenticated ID cannot be replayed after logout', status == 302)
status, _, _ = request(opener, '/api/products/BIS-0001/availability')
check('Logout denies protected API', status == 401)
print(json.dumps({'checks':len(results),'passed':results}, indent=2))
