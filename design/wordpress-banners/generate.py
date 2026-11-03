from pathlib import Path
from base64 import b64encode
from html import escape
root=Path(__file__).resolve().parents[2]
out=Path(__file__).resolve().parent
copy={
 '':('en','Volunteer management','Plan shifts.','Bring people together.','Self-service signup. Clear shift planning.','Shift planning','Volunteer signup'),
 '-de':('de','Freiwilligenmanagement','Schichten planen.','Gemeinsam anpacken.','Selbst eintragen. Den Überblick behalten.','Schichtplanung','Selbst eintragen'),
 '-fr':('fr','Gestion des bénévoles','Planifiez les créneaux.','Mobilisez votre équipe.','Inscriptions autonomes. Planning clair.','Planning des créneaux','Inscription des bénévoles'),
}
main='data:image/png;base64,'+b64encode((root/'.wordpress-org/screenshot-1.png').read_bytes()).decode()
vol='data:image/png;base64,'+b64encode((root/'.wordpress-org/screenshot-2.png').read_bytes()).decode()
for suffix,(lang,subtitle,line1,line2,foot,manager,signup) in copy.items():
 text=lambda s:escape(s)
 svg=f'''<svg xmlns="http://www.w3.org/2000/svg" width="1544" height="500" viewBox="0 0 1544 500">
<title>EventAdmin — {text(subtitle)}</title>
<defs>
<linearGradient id="bg" x2="1" y2="1"><stop stop-color="#edf5f4"/><stop offset="1" stop-color="#d1e6e5"/></linearGradient>
<filter id="shadow" x="-25%" y="-25%" width="160%" height="170%"><feDropShadow dx="0" dy="8" stdDeviation="10" flood-color="#233b4b" flood-opacity=".22"/></filter>
<clipPath id="manager"><rect x="642" y="56" width="800" height="392" rx="12"/></clipPath>
<clipPath id="signup"><rect x="1212" y="242" width="280" height="198" rx="3"/></clipPath>
</defs>
<rect width="1544" height="500" fill="url(#bg)"/>
<circle cx="1450" cy="-40" r="420" fill="#fff" opacity=".2"/>
<g font-family="Arial, Helvetica, sans-serif">
<text x="50" y="54" font-size="17" letter-spacing="2.4" fill="#3f767d">WORDPRESS · OPEN SOURCE</text>
<text x="46" y="137" font-size="72" font-weight="700" letter-spacing="-2.5" fill="#2d4051">EventAdmin</text>
<text x="50" y="178" font-size="26" fill="#506774">{text(subtitle)}</text>
<rect x="50" y="215" width="58" height="5" rx="2" fill="#59979b"/>
<text x="48" y="287" font-size="41" font-weight="700" letter-spacing="-1" fill="#2d4051">{text(line1)}</text>
<text x="48" y="339" font-size="41" font-weight="700" letter-spacing="-1" fill="#2d4051">{text(line2)}</text>
<text x="50" y="399" font-size="22" fill="#506774">{text(foot)}</text>
<text x="50" y="458" font-size="17" fill="#456571">by sidefyn</text>
<rect x="642" y="56" width="800" height="392" rx="12" fill="#fff" filter="url(#shadow)"/>
<g clip-path="url(#manager)">
<rect x="642" y="56" width="800" height="34" fill="#2d4051"/>
<circle cx="660" cy="73" r="4" fill="#91b3bd"/><circle cx="674" cy="73" r="4" fill="#91b3bd"/><circle cx="688" cy="73" r="4" fill="#91b3bd"/>
<text x="1042" y="79" text-anchor="middle" font-size="15" fill="#fff">{text(manager)}</text>
<svg x="642" y="90" width="800" height="358" viewBox="185 37 1103 494" preserveAspectRatio="xMinYMin slice"><image href="{main}" width="1288" height="932"/></svg>
</g>
<rect x="1200" y="188" width="304" height="264" rx="14" fill="#fff" filter="url(#shadow)"/>
<rect x="1212" y="200" width="280" height="36" rx="5" fill="#e8f3f2"/>
<text x="1352" y="223" text-anchor="middle" font-size="15" font-weight="700" fill="#2d4051">{text(signup)}</text>
<g clip-path="url(#signup)"><svg x="1212" y="242" width="280" height="198" viewBox="285 195 800 566" preserveAspectRatio="xMinYMin slice"><image href="{vol}" width="1372" height="894"/></svg></g>
</g></svg>'''
 (out/f'banner-1544x500{suffix}.svg').write_text(svg)
 (out/f'preview{suffix or "-en"}.html').write_text(f'<!doctype html><html lang="{lang}"><meta charset="utf-8"><title>EventAdmin banner {lang}</title><style>html,body{{margin:0}}img{{display:block;width:1544px;height:500px}}</style><img src="banner-1544x500{suffix}.svg" alt="EventAdmin {text(subtitle)}">')
(out/'index.html').write_text('''<!doctype html><meta charset="utf-8"><title>EventAdmin banner proposals</title><style>body{font-family:Arial;background:#f4f6f7;padding:24px;color:#2d4051}img{display:block;width:772px;height:250px;margin-bottom:30px;border:1px solid #d7e1e4}</style><h1>WordPress.org banner proposals</h1>'''+''.join(f'<h2>{name}</h2><img src="banner-1544x500{s}.svg">' for s,name in [('', 'English'),('-de','Deutsch'),('-fr','Français')]))
print('Generated three SVG masters and browser previews.')
