#!/usr/bin/env python3
"""Genere la documentation technique a partir du code source PHP du projet."""
import os, re, sys, datetime

RACINE = sys.argv[1]
SORTIE = sys.argv[2]

RE_NS      = re.compile(r'^namespace\s+([^;]+);', re.M)
RE_CLASS   = re.compile(r'^(?:final\s+|abstract\s+)?(class|interface|trait|enum)\s+(\w+)(?:\s+extends\s+([\w\\]+))?(?:\s+implements\s+([\w\\, ]+))?', re.M)
RE_METHOD  = re.compile(r'^\s{4}(?:(public|protected|private)\s+)?(?:static\s+)?function\s+(\w+)\s*\(([^)]*)\)(?:\s*:\s*([^\s{]+))?', re.M)
RE_CONST   = re.compile(r'^\s{4}(?:public\s+)?const\s+(\w+)', re.M)
RE_PROP    = re.compile(r'^\s{4}(?:protected|public|private)\s+(?:readonly\s+)?(?:\??[\w\\|]+\s+)?\$(\w+)', re.M)
RE_DOC     = re.compile(r'/\*\*(.*?)\*/\s*$', re.S)

def docblock_avant(texte, position):
    """Recupere le commentaire /** */ precedant immediatement une position."""
    avant = texte[:position]
    m = RE_DOC.search(avant)
    if not m:
        return ""
    lignes = []
    for ligne in m.group(1).split("\n"):
        ligne = ligne.strip().lstrip("*").strip()
        if ligne and not ligne.startswith("@"):
            lignes.append(ligne)
    return " ".join(lignes)

def analyser(chemin):
    texte = open(chemin, encoding="utf-8", errors="replace").read()
    ns = RE_NS.search(texte)
    mc = RE_CLASS.search(texte)
    if not mc:
        return None
    return {
        "fichier": os.path.relpath(chemin, RACINE),
        "namespace": ns.group(1) if ns else "",
        "type": mc.group(1),
        "nom": mc.group(2),
        "extends": mc.group(3) or "",
        "implements": (mc.group(4) or "").strip(),
        "doc": docblock_avant(texte, mc.start()),
        "constantes": RE_CONST.findall(texte),
        "proprietes": sorted(set(RE_PROP.findall(texte))),
        "methodes": [
            {
                "visibilite": v or "public",
                "nom": n,
                "params": " ".join(p.split()),
                "retour": r or "",
                "doc": docblock_avant(texte, texte.index("function " + n + "(")),
            }
            for v, n, p, r in RE_METHOD.findall(texte)
        ],
    }

GROUPES = [
    ("Controleurs HTTP",      "app/Http/Controllers"),
    ("Middlewares",           "app/Http/Middleware"),
    ("Validation des requetes","app/Http/Requests"),
    ("Ressources JSON",       "app/Http/Resources"),
    ("Modeles de donnees",    "app/Models"),
    ("Services metier",       "app/Services"),
    ("Classes utilitaires",   "app/Support"),
    ("Fournisseurs de services","app/Providers"),
    ("Tests",                 "tests"),
]

fiches = []
for chemin_racine, _, fichiers in os.walk(os.path.join(RACINE, "app")):
    for f in sorted(fichiers):
        if f.endswith(".php"):
            r = analyser(os.path.join(chemin_racine, f))
            if r: fiches.append(r)
for chemin_racine, _, fichiers in os.walk(os.path.join(RACINE, "tests")):
    for f in sorted(fichiers):
        if f.endswith(".php"):
            r = analyser(os.path.join(chemin_racine, f))
            if r: fiches.append(r)

os.makedirs(SORTIE, exist_ok=True)

with open(os.path.join(SORTIE, "reference_classes.md"), "w", encoding="utf-8") as out:
    out.write("# Reference des classes\n\n")
    out.write("> Documentation generee automatiquement a partir du code source du projet, ")
    out.write("hors dependances tierces (`vendor/`, `node_modules/`).\n\n")
    out.write(f"Genere le {datetime.date.today().isoformat()} par `deploy/gendoc.py`.\n\n")
    out.write(f"**{len(fiches)} classes** analysees.\n\n---\n\n")

    for titre, prefixe in GROUPES:
        lot = [f for f in fiches if f["fichier"].startswith(prefixe)]
        if not lot:
            continue
        out.write(f"## {titre}\n\n")
        for f in sorted(lot, key=lambda x: x["fichier"]):
            out.write(f"### `{f['nom']}`\n\n")
            out.write(f"| | |\n| --- | --- |\n")
            out.write(f"| Fichier | `{f['fichier']}` |\n")
            out.write(f"| Namespace | `{f['namespace']}` |\n")
            out.write(f"| Type | {f['type']} |\n")
            if f["extends"]:
                out.write(f"| Herite de | `{f['extends']}` |\n")
            if f["implements"]:
                out.write(f"| Implemente | `{f['implements']}` |\n")
            out.write("\n")
            if f["doc"]:
                out.write(f"{f['doc']}\n\n")
            if f["constantes"]:
                out.write("**Constantes** : " + ", ".join(f"`{c}`" for c in f["constantes"]) + "\n\n")
            if f["proprietes"]:
                out.write("**Proprietes** : " + ", ".join(f"`${p}`" for p in f["proprietes"]) + "\n\n")
            publiques = [m for m in f["methodes"] if m["visibilite"] == "public"]
            autres    = [m for m in f["methodes"] if m["visibilite"] != "public"]
            if publiques:
                out.write("**Methodes publiques**\n\n")
                out.write("| Signature | Retour | Description |\n| --- | --- | --- |\n")
                for m in publiques:
                    p = m["params"][:70] + ("..." if len(m["params"]) > 70 else "")
                    d = m["doc"][:110] if m["doc"] else ""
                    out.write(f"| `{m['nom']}({p})` | `{m['retour'] or '-'}` | {d} |\n")
                out.write("\n")
            if autres:
                out.write("**Methodes internes** : " + ", ".join(f"`{m['nom']}()`" for m in autres) + "\n\n")
            out.write("---\n\n")

print(f"{len(fiches)} classes documentees")
