#!/usr/bin/env python3
"""Importe le backlog SideKey (docs/backlog/backlog.json) dans GitHub.

Crée les labels, les jalons et les issues, puis les ajoute au GitHub Project
avec les champs Priorité, Attribution, Estimation, Jour et ID.
Relançable sans créer de doublons : une issue est retrouvée par son préfixe "[ID]".

Prérequis : gh auth login, puis gh auth refresh -s project

Exemples :
  python3 scripts/import_backlog.py --md                      # régénère backlog_SideKey.md
  python3 scripts/import_backlog.py --repo OWNER/SideKey --owner OWNER --project 1 \
      --m1 login1 --m2 login2 --dry-run
"""
import argparse
import json
import re
import subprocess
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
BACKLOG = ROOT / "docs" / "backlog" / "backlog.json"
MD_OUT = ROOT / "docs" / "backlog" / "backlog_SideKey.md"

TYPE_LABELS = {
    "conception": ("type:conception", "5319e7", "Analyse, maquettes, modélisation"),
    "technique": ("type:technique", "0e8a16", "Socle technique, BDD, CI/CD"),
    "feature": ("type:feature", "1d76db", "Fonctionnalité de bout en bout"),
    "pool": ("type:pool", "c5def5", "Feature secondaire attribuée selon l'avancement"),
    "livraison": ("type:livraison", "fbca04", "Mise en production, dossier, oral"),
}
PRIO_LABELS = {
    "M": ("prio:M", "b60205", "Must — prioritaire"),
    "S": ("prio:S", "d93f0b", "Should — pool, en tête"),
    "C": ("prio:C", "fef2c0", "Could — si le temps le permet"),
}
FIELDS = {
    "Priorité": ("SINGLE_SELECT", ["M", "S", "C"]),
    "Attribution": ("SINGLE_SELECT", ["Ensemble", "M1", "M2", "Pool"]),
    "Estimation": ("NUMBER", None),
    "Jour": ("TEXT", None),
    "ID": ("TEXT", None),
}


def load():
    return json.loads(BACKLOG.read_text(encoding="utf-8"))


def issue_title(item):
    return f"[{item['id']}] {item['title']}"


def issue_body(item, data, numbers):
    lines = ["## Description", "", item["description"], "", "## Critères d'acceptation", ""]
    lines += [f"- [ ] {a}" for a in item["acceptance"]]
    lines += ["", "## Dépendances", ""]
    if item["deps"]:
        for d in item["deps"]:
            lines.append(f"- #{numbers[d]} ({d})" if d in numbers else f"- {d}")
    else:
        lines.append("Aucune")
    lines += [
        "", "## Infos", "",
        "| ID | Attribution | Priorité | Estimation | Jour |",
        "|---|---|---|---|---|",
        f"| {item['id']} | {item['attribution']} | {item['priority']} | {item['estimate']} j | {item['day']} |",
        "", "## Définition de terminé", "",
    ]
    lines += [f"- [ ] {d}" for d in data["definition_of_done"]]
    return "\n".join(lines) + "\n"


def write_markdown(data):
    ms = data["milestones"]
    out = ["# Backlog SideKey", "",
           "> Généré depuis `docs/backlog/backlog.json` par `scripts/import_backlog.py --md`. "
           "Modifier le JSON, pas ce fichier.", "",
           "## Vue d'ensemble", "",
           "| ID | Titre | Attribution | Prio | Est. (j) | Jour | Jalon | Dépend de |",
           "|---|---|---|---|---|---|---|---|"]
    for it in data["items"]:
        out.append(f"| {it['id']} | {it['title']} | {it['attribution']} | {it['priority']} | "
                   f"{it['estimate']} | {it['day']} | {ms.get(it['milestone'], 'Pool')} | "
                   f"{', '.join(it['deps']) or '—'} |")
    charge = {}
    for it in data["items"]:
        charge[it["attribution"]] = charge.get(it["attribution"], 0) + it["estimate"]
    out += ["", "**Charge estimée** : " + " · ".join(f"{k} {v:g} j" for k, v in charge.items()), ""]
    out += ["## Règles d'attribution du pool", "",
            "- Le premier membre libre prend la feature du pool la mieux classée.",
            "- Chacun ne mène qu'une feature à la fois.",
            "- Une feature en retard est réattribuée à la revue du soir.",
            "- Aucune nouvelle feature n'est lancée après le J10.", "",
            "## Définition de terminé (commune à toutes les issues)", ""]
    out += [f"- {d}" for d in data["definition_of_done"]]
    out += ["", "## Détail", ""]
    for it in data["items"]:
        out += [f"### {it['id']} — {it['title']}", "",
                f"*{it['attribution']} · priorité {it['priority']} · {it['estimate']} j · {it['day']}"
                f" · dépend de : {', '.join(it['deps']) or 'aucune'}*", "",
                it["description"], ""]
        out += [f"- [ ] {a}" for a in it["acceptance"]]
        out.append("")
    MD_OUT.write_text("\n".join(out), encoding="utf-8")
    print(f"Écrit : {MD_OUT.relative_to(ROOT)}")


class GH:
    def __init__(self, dry_run):
        self.dry = dry_run

    def run(self, args, mutate=True, input_text=None):
        if self.dry and mutate:
            print("  [dry-run] gh " + " ".join(a if " " not in a else repr(a) for a in args))
            return ""
        for attempt in range(4):
            r = subprocess.run(["gh", *args], input=input_text, capture_output=True, text=True)
            if r.returncode == 0:
                return r.stdout
            transient = re.search(r"status code: (499|5\d\d)|timeout|rate limit", r.stderr, re.I)
            if not transient or attempt == 3:
                break
            time.sleep(2 ** (attempt + 1))
        sys.exit(f"Erreur gh {' '.join(args[:3])}…\n{r.stderr.strip()}")

    def json(self, args):
        out = self.run(args, mutate=False)
        return json.loads(out) if out.strip() else None


def assignees(item, a):
    return {"M1": [a.m1], "M2": [a.m2], "Ensemble": [a.m1, a.m2]}.get(item["attribution"], [])


def main():
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--md", action="store_true", help="régénère seulement backlog_SideKey.md")
    p.add_argument("--repo")
    p.add_argument("--owner")
    p.add_argument("--project", type=int)
    p.add_argument("--m1")
    p.add_argument("--m2")
    p.add_argument("--dry-run", action="store_true", help="n'écrit rien sur GitHub")
    a = p.parse_args()
    data = load()

    if a.md:
        write_markdown(data)
        return
    for k in ("repo", "owner", "project", "m1", "m2"):
        if getattr(a, k) is None:
            p.error(f"--{k} est obligatoire")

    gh = GH(a.dry_run)
    repo = a.repo

    print("== Labels")
    for name, color, desc in [*TYPE_LABELS.values(), *PRIO_LABELS.values()]:
        gh.run(["label", "create", name, "--repo", repo, "--color", color, "--description", desc, "--force"])

    print("== Jalons")
    existing_ms = set()
    if not a.dry_run:
        existing_ms = {m["title"] for m in gh.json(["api", f"repos/{repo}/milestones?state=all&per_page=100"])}
    for title in data["milestones"].values():
        if title not in existing_ms:
            gh.run(["api", "-X", "POST", f"repos/{repo}/milestones", "-f", f"title={title}"])

    print("== Issues")
    numbers, urls = {}, {}
    if not a.dry_run:
        for i in gh.json(["issue", "list", "--repo", repo, "--state", "all", "--limit", "500",
                          "--json", "number,title,url"]):
            m = re.match(r"\[([A-Z]-\d+)\]", i["title"])
            if m:
                numbers[m.group(1)], urls[m.group(1)] = i["number"], i["url"]
    for it in data["items"]:
        if it["id"] in numbers:
            print(f"  existe déjà : {it['id']} (#{numbers[it['id']]})")
            continue
        labels = [TYPE_LABELS[it["type"]][0], PRIO_LABELS[it["priority"]][0]]
        args = ["issue", "create", "--repo", repo, "--title", issue_title(it), "--body-file", "-"]
        for l in labels:
            args += ["--label", l]
        if it["milestone"]:
            args += ["--milestone", data["milestones"][it["milestone"]]]
        for who in assignees(it, a):
            args += ["--assignee", who]
        url = gh.run(args, input_text=issue_body(it, data, numbers)).strip()
        if url:
            numbers[it["id"]], urls[it["id"]] = int(url.rstrip("/").split("/")[-1]), url
            print(f"  créée : {it['id']} → #{numbers[it['id']]}")

    print("== Dépendances (#n)")
    for it in data["items"]:
        if it["deps"] and it["id"] in numbers:
            gh.run(["issue", "edit", str(numbers[it["id"]]), "--repo", repo, "--body-file", "-"],
                   input_text=issue_body(it, data, numbers))

    print("== Project")
    if a.dry_run:
        for name, (kind, opts) in FIELDS.items():
            print(f"  [dry-run] champ {name} ({kind}{': ' + ','.join(opts) if opts else ''})")
        print(f"  [dry-run] ajout de {len(data['items'])} issues au Project {a.owner}/{a.project} et remplissage des champs")
        return
    proj = gh.json(["project", "view", str(a.project), "--owner", a.owner, "--format", "json"])
    pid = proj["id"]

    def fields():
        return {f["name"]: f for f in gh.json(["project", "field-list", str(a.project), "--owner", a.owner,
                                                "--format", "json", "--limit", "100"])["fields"]}
    f = fields()
    for name, (kind, opts) in FIELDS.items():
        if name not in f:
            args = ["project", "field-create", str(a.project), "--owner", a.owner, "--name", name, "--data-type", kind]
            if opts:
                args += ["--single-select-options", ",".join(opts)]
            gh.run(args)
    f = fields()

    items = gh.json(["project", "item-list", str(a.project), "--owner", a.owner, "--format", "json", "--limit", "500"])
    item_ids = {i["content"].get("number"): i["id"] for i in items["items"] if i.get("content")}

    for it in data["items"]:
        n = numbers[it["id"]]
        iid = item_ids.get(n)
        if not iid:
            iid = gh.json(["project", "item-add", str(a.project), "--owner", a.owner, "--url", urls[it["id"]],
                           "--format", "json"])["id"]
        base = ["project", "item-edit", "--id", iid, "--project-id", pid]
        for name, value in (("Priorité", it["priority"]), ("Attribution", it["attribution"])):
            opt = next(o["id"] for o in f[name]["options"] if o["name"] == value)
            gh.run(base + ["--field-id", f[name]["id"], "--single-select-option-id", opt])
        gh.run(base + ["--field-id", f["Estimation"]["id"], "--number", str(it["estimate"])])
        gh.run(base + ["--field-id", f["Jour"]["id"], "--text", it["day"]])
        gh.run(base + ["--field-id", f["ID"]["id"], "--text", it["id"]])
        print(f"  Project : {it['id']} (#{n}) à jour")

    print("\nTerminé. Reste à renommer à la main les options du champ Status : "
          "Backlog / Jour J / En cours / En revue / Terminé.")


if __name__ == "__main__":
    main()
