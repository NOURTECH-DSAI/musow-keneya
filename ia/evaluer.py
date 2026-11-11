#!/usr/bin/env python3
"""
Musow Kènèya : évaluation de la reconnaissance des questions vocales.

Produit les chiffres du dossier UNICEF à partir de vraies questions enregistrées
par des femmes : taux de bonne reconnaissance, couverture au seuil choisi, taux
de réponses inexactes, détection des signes de danger, délai de transcription.

Préparation :
  1. Faites enregistrer par 15 à 20 femmes, sur WhatsApp ou au téléphone,
     les questions de la bibliothèque, chacune avec ses propres mots, plus
     quelques questions hors bibliothèque (code « AUCUNE »).
  2. Placez les fichiers dans un dossier et décrivez-les dans un CSV
     (séparateur « ; ») :
         fichier;code_attendu;locutrice
         f01_q026.ogg;Q026;F01
         f01_hors.ogg;AUCUNE;F01
     Pour mesurer la confirmation à voix haute, ajoutez des réponses courtes
     avec les codes OUI et NON (au moins 10 par femme, mélangées) :
         f01_oui1.ogg;OUI;F01     (« ɔnhɔn », ou « awɔ » pour une locutrice du bambara)
         f01_non1.ogg;NON;F01     (« ɔn'ɔn », ou « ayi »)
  3. IMPORTANT : ces enregistrements de test ne doivent pas avoir été ajoutés
     comme formulations dans la bibliothèque, sinon le résultat est faussé.

Usage (Colab ou ordinateur) :
    export MK_URL=... MK_JETON_IA=... MK_MODELE=facebook/mms-1b-all
    python3 evaluer.py dossier_audios/ etiquettes.csv --profil enceinte
Résultats : evaluation_details.csv et un résumé à l'écran (à copier dans le dossier).
"""
from __future__ import annotations

import argparse
import csv
import os
import statistics
import time
from pathlib import Path

import requests

from worker import JETON, MODELE, URL, Transcripteur, decoder_audio


def apparier(texte: str, profil: str | None) -> dict:
    r = requests.post(
        f"{URL}/api/ia.php", params={"action": "apparier"},
        headers={"Authorization": f"Bearer {JETON}"}, json={"texte": texte, "profil": profil}, timeout=30,
    )
    r.raise_for_status()
    return r.json()


def decision(res: dict, score_audio: float, seuil_audio: float) -> str:
    """Reproduit la règle de la plateforme (src/Questions.php)."""
    if res.get("danger"):
        return "DANGER"
    if score_audio < seuil_audio:
        return "AUCUNE"
    c = res.get("candidats", [])
    if not c or c[0]["score"] < res["seuil"]:
        return "AUCUNE"
    if len(c) > 1 and c[0]["score"] - c[1]["score"] < res["marge"]:
        return "AUCUNE"
    return c[0]["code"]


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("dossier")
    ap.add_argument("etiquettes")
    ap.add_argument("--profil", choices=["enceinte", "mere"], default=None)
    ap.add_argument("--seuil-audio", type=float, default=0.45)
    ap.add_argument("--codes-danger", default="", help="codes des questions marquées « signe de danger », séparés par des virgules")
    a = ap.parse_args()

    seuil_oui = float(os.environ.get("MK_SEUIL_AUDIO_OUI", "0.60"))
    codes_danger = {c.strip().upper() for c in a.codes_danger.split(",") if c.strip()}
    transcrire = Transcripteur(MODELE)
    lignes = list(csv.DictReader(open(a.etiquettes, encoding="utf-8-sig"), delimiter=";"))
    details, durees = [], []
    for l in lignes:
        chemin = Path(a.dossier) / l["fichier"]
        debut = time.time()
        texte, score = transcrire(decoder_audio(chemin.read_bytes()))
        durees.append(time.time() - debut)
        res = apparier(texte, a.profil)
        attendu0 = l["code_attendu"].strip().upper()
        if attendu0 in ("OUI", "NON"):
            lu = res.get("oui_non") or "QUESTION"
            if lu == "oui" and score < seuil_oui:
                lu = "incertain"
            d = lu.upper()
        else:
            d = decision(res, score, a.seuil_audio)
        top1 = res["candidats"][0]["code"] if res.get("candidats") else ""
        attendu = l["code_attendu"].strip().upper()
        details.append({
            "fichier": l["fichier"], "locutrice": l.get("locutrice", ""), "attendu": attendu,
            "transcription": texte, "confiance_audio": round(score, 3), "plus_proche": top1,
            "score": res["candidats"][0]["score"] if res.get("candidats") else 0, "decision": d,
        })
        print(f"{l['fichier']}: attendu {attendu} · décision {d} · « {texte} »", flush=True)

    with open("evaluation_details.csv", "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=list(details[0].keys()), delimiter=";")
        w.writeheader()
        w.writerows(details)

    oui_non = [x for x in details if x["attendu"] in ("OUI", "NON")]
    details_q = [x for x in details if x["attendu"] not in ("OUI", "NON")]
    dans = [x for x in details_q if x["attendu"] not in ("AUCUNE", "")]
    hors = [x for x in details_q if x["attendu"] == "AUCUNE"]
    danger_attendu = [x for x in dans if x["attendu"] in codes_danger]
    top1_ok = sum(x["plus_proche"] == x["attendu"] for x in dans)
    repondues = [x for x in dans if x["decision"] not in ("AUCUNE", "DANGER")]
    justes = sum(x["decision"] == x["attendu"] for x in repondues)
    fausses_hors = sum(x["decision"] not in ("AUCUNE", "DANGER") for x in hors)
    danger_ok = sum(x["decision"] == "DANGER" or x["decision"] == x["attendu"] for x in danger_attendu)
    pct = lambda n, d: f"{100 * n / d:.1f} %" if d else "–"

    print("\n=== Résultats ===")
    print(f"Enregistrements : {len(details)} ({len(dans)} questions de la bibliothèque, {len(hors)} hors bibliothèque), {len({x['locutrice'] for x in details})} locutrices")
    print(f"Question la plus proche correcte : {pct(top1_ok, len(dans))}")
    print(f"Couverture (réponse proposée) : {pct(len(repondues), len(dans))}")
    print(f"Réponses proposées exactes : {pct(justes, len(repondues))}  (erreurs : {len(repondues) - justes})")
    print(f"Questions hors bibliothèque à qui on a proposé une réponse : {pct(fausses_hors, len(hors))}")
    if danger_attendu:
        print(f"Signes de danger correctement orientés vers l'urgence : {pct(danger_ok, len(danger_attendu))}")
    if oui_non:
        ouis = [x for x in oui_non if x["attendu"] == "OUI"]
        nons = [x for x in oui_non if x["attendu"] == "NON"]
        print(f"Confirmation à voix haute : {len(ouis)} « oui », {len(nons)} « non »")
        print(f"  « oui » compris comme oui : {pct(sum(x['decision'] == 'OUI' for x in ouis), len(ouis))} (sinon : rappel par une sage-femme)")
        print(f"  « non » compris comme non : {pct(sum(x['decision'] == 'NON' for x in nons), len(nons))}")
        faux_oui = sum(x["decision"] == "OUI" for x in nons)
        print(f"  « non » pris pour un oui (erreur grave, viser 0) : {faux_oui} sur {len(nons)}")
    print(f"Durée de transcription : médiane {statistics.median(durees):.1f} s")
    print("Détail : evaluation_details.csv")
    print("\nAjustez « questions.seuil_reconnaissance » dans config.php pour viser zéro erreur, quitte à transmettre davantage à l'équipe.")


if __name__ == "__main__":
    main()
