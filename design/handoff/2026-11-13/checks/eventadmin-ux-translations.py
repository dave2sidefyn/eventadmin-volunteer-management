from pathlib import Path
import subprocess,polib
translations={
'Search volunteers…':['Volunteers suchen…','Rechercher des bénévoles…','Vrijwilligers zoeken…','Søk etter frivillige…'],
'Shift list':['Schichtliste','Liste des créneaux','Dienstenlijst','Vaktliste'],
'Registration activity':['Registrierungsaktivität','Activité des inscriptions','Registratieactiviteit','Registreringsaktivitet'],
'More details':['Weitere Details','Plus de détails','Meer details','Flere detaljer'],
'Volunteer dashboard':['Volunteer-Übersicht','Tableau de bord des bénévoles','Vrijwilligersdashboard','Oversikt for frivillige'],
'Profile':['Profil','Profil','Profiel','Profil'],
'Sign up for this shift':['Für diese Schicht anmelden',"S’inscrire à ce créneau",'Aanmelden voor deze dienst','Meld deg på denne vakten'],
'Cancel my sign-up':['Meine Anmeldung stornieren',"Annuler mon inscription",'Mijn aanmelding annuleren','Avbryt påmeldingen min'],
'Departments I want updates about':['Abteilungen, über die ich informiert werden möchte',"Départements dont je souhaite recevoir les actualités",'Afdelingen waarover ik updates wil ontvangen','Avdelinger jeg ønsker oppdateringer om'],
'These departments can send you updates independently of general announcements. This does not change which shifts you can sign up for.':['Diese Abteilungen können dir unabhängig von allgemeinen Mitteilungen Updates senden. Dies ändert nicht, für welche Schichten du dich anmelden kannst.',"Ces départements peuvent vous envoyer des actualités indépendamment des annonces générales. Cela ne change pas les créneaux auxquels vous pouvez vous inscrire.",'Deze afdelingen kunnen je updates sturen, onafhankelijk van algemene aankondigingen. Dit verandert niet voor welke diensten je je kunt aanmelden.','Disse avdelingene kan sende deg oppdateringer uavhengig av generelle kunngjøringer. Dette endrer ikke hvilke vakter du kan melde deg på.'],
'Register, then check your email for a login link. No password needed. All fields are required.':['Registriere dich und prüfe danach deine E-Mails auf den Login-Link. Kein Passwort nötig. Alle Felder sind Pflichtfelder.',"Inscrivez-vous, puis consultez vos e-mails pour obtenir un lien de connexion. Aucun mot de passe nécessaire. Tous les champs sont obligatoires.",'Registreer je en kijk daarna in je e-mail voor een inloglink. Geen wachtwoord nodig. Alle velden zijn verplicht.','Registrer deg, og sjekk deretter e-posten din for en innloggingslenke. Du trenger ikke passord. Alle feltene er obligatoriske.'],
'So we can reach you about last-minute changes.':['Damit wir dich bei kurzfristigen Änderungen erreichen können.',"Pour que nous puissions vous joindre en cas de changements de dernière minute.",'Zodat we je kunnen bereiken bij wijzigingen op het laatste moment.','Slik at vi kan nå deg ved endringer i siste liten.'],
'Register and email me a login link':['Registrieren und Login-Link per E-Mail erhalten',"M’inscrire et recevoir un lien de connexion",'Registreren en mij een inloglink mailen','Registrer meg og send en innloggingslenke'],
'%d place left':[['%d Platz frei','%d Plätze frei'],['%d place disponible','%d places disponibles'],['%d plek vrij','%d plekken vrij'],['%d ledig plass','%d ledige plasser']],
'Find a shift':['Schicht finden','Trouver un créneau','Een dienst zoeken','Finn en vakt'],
'%1$s — %2$s':['%1$s — %2$s']*4,
}
formal={
'These departments can send you updates independently of general announcements. This does not change which shifts you can sign up for.':'Diese Abteilungen können Ihnen unabhängig von allgemeinen Mitteilungen Updates senden. Dies ändert nicht, für welche Schichten Sie sich anmelden können.',
'Register, then check your email for a login link. No password needed. All fields are required.':'Registrieren Sie sich und prüfen Sie danach Ihre E-Mails auf den Login-Link. Kein Passwort nötig. Alle Felder sind Pflichtfelder.',
'So we can reach you about last-minute changes.':'Damit wir Sie bei kurzfristigen Änderungen erreichen können.',
}
for path in Path('languages').glob('*.po'):
 subprocess.run(['msgmerge','--no-fuzzy-matching','--quiet','--update','--backup=none',str(path),'languages/eventadmin-volunteer-management.pot'],check=True)
 po=polib.pofile(str(path));lang=po.metadata['Language'];idx=next(i for i,k in enumerate(['de','fr','nl','nb']) if lang.startswith(k))
 for e in po.untranslated_entries():
  assert e.msgid in translations,(path,e.msgid)
  value=translations[e.msgid][idx]
  if lang=='de_DE_formal': value=formal.get(e.msgid,value)
  if lang=='nl_NL_formal' and isinstance(value,str):
   value=value.replace('je updates','u updates').replace('je kunt','u kunt').replace('Registreer je','Registreer u').replace('je e-mail','uw e-mail').replace('we je kunnen','we u kunnen')
  if 'de_CH' in lang and isinstance(value,str): value=value.replace('ß','ss')
  if e.msgid_plural: e.msgstr_plural={0:value[0],1:value[1]}
  else: e.msgstr=value
 po.save(str(path))
 subprocess.run(['msgfmt','--check-format','-o',str(path.with_suffix('.mo')),str(path)],check=True)
 assert not po.untranslated_entries() and not po.fuzzy_entries(),path
 print(lang+': complete')
