#!/usr/bin/env python3
"""
Musow Kènèya : service de reconnaissance vocale du dioula.

Le service interroge la plateforme toutes les 5 secondes, transcrit chaque
question vocale reçue et renvoie la transcription. C'est la plateforme qui
décide ensuite (question reconnue, signe de danger, transfert à l'équipe) :
ce programme ne répond jamais lui-même aux femmes.

Installation (ordinateur, serveur ou VPS avec Python 3.10+) :
    sudo apt install ffmpeg
    pip install torch transformers requests numpy
    export MK_URL="https://<domaine>/musow"
    export MK_JETON_IA="<securite.jeton_ia de config.php>"
    export MK_MODELE="facebook/mms-1b-all"      # démonstration et recherche
    # export MK_MODELE="chemin/vers/xlsr-dyu"   # modèle propre (Apache 2.0) pour la production
    python3 worker.py --boucle

Licences : MMS (facebook/mms-1b-all) est sous CC BY-NC 4.0, utilisation non
commerciale. Il convient au prototype et à la recherche. Le service exploité
par NOUR TECH devra utiliser un modèle sous licence permissive, par exemple
XLS-R (Apache 2.0) affiné sur le corpus MEY-D26 (section 7.3 du cahier des
charges).
"""
from __future__ import annotations

import os
import subprocess
import sys
import time

import numpy as np
import requests

URL = os.environ.get("MK_URL", "").rstrip("/")
JETON = os.environ.get("MK_JETON_IA", "")
MODELE = os.environ.get("MK_MODELE", "facebook/mms-1b-all")
LANGUE_MMS = os.environ.get("MK_LANGUE_MMS", "dyu")  # code ISO 639-3 du dioula
INTERVALLE = float(os.environ.get("MK_INTERVALLE", "5"))
TAUX = 16000


def entetes() -> dict:
    return {"Authorization": f"Bearer {JETON}"}


def decoder_audio(octets: bytes) -> np.ndarray:
    """OGG Opus (WhatsApp) ou tout autre format → signal mono 16 kHz, via ffmpeg."""
    proc = subprocess.run(
        ["ffmpeg", "-hide_banner", "-loglevel", "error", "-i", "pipe:0", "-ac", "1", "-ar", str(TAUX), "-f", "f32le", "pipe:1"],
        input=octets, capture_output=True, check=True,
    )
    return np.frombuffer(proc.stdout, dtype=np.float32)


class Transcripteur:
    """Modèle CTC (MMS avec adaptateur dioula, ou XLS-R affiné)."""

    def __init__(self, modele: str):
        import torch
        from transformers import AutoProcessor, Wav2Vec2ForCTC

        self.torch = torch
        self.nom = modele.rstrip("/").split("/")[-1]
        if "mms" in modele.lower():
            self.processor = AutoProcessor.from_pretrained(modele)
            self.processor.tokenizer.set_target_lang(LANGUE_MMS)
            self.model = Wav2Vec2ForCTC.from_pretrained(modele)
            self.model.load_adapter(LANGUE_MMS)
            self.nom += f"-{LANGUE_MMS}"
        else:
            self.processor = AutoProcessor.from_pretrained(modele)
            self.model = Wav2Vec2ForCTC.from_pretrained(modele)
        self.appareil = "cuda" if torch.cuda.is_available() else "cpu"
        self.model.to(self.appareil).eval()

    def __call__(self, signal: np.ndarray) -> tuple[str, float]:
        if signal.size < TAUX // 4:
            return "", 0.0
        # Les questions dépassent rarement 30 s ; on tronque par prudence mémoire.
        signal = signal[: TAUX * 60]
        entree = self.processor(signal, sampling_rate=TAUX, return_tensors="pt").to(self.appareil)
        with self.torch.no_grad():
            logits = self.model(**entree).logits[0]
        probas = logits.softmax(-1)
        ids = probas.argmax(-1)
        texte = self.processor.decode(ids.cpu())
        # Confiance : probabilité moyenne sur les trames qui ne sont pas du silence
        pad = self.processor.tokenizer.pad_token_id
        masque = ids != pad
        score = float(probas.max(-1).values[masque].mean()) if bool(masque.any()) else 0.0
        return texte.strip(), score


def une_passe(transcrire: Transcripteur) -> int:
    r = requests.get(f"{URL}/api/ia.php", params={"action": "a_faire"}, headers=entetes(), timeout=30)
    r.raise_for_status()
    n = 0
    for v in r.json().get("vocaux", []):
        audio = requests.get(f"{URL}/api/ia.php", params={"action": "audio", "id": v["id"]}, headers=entetes(), timeout=60)
        if audio.status_code != 200:
            continue
        debut = time.time()
        try:
            texte, score = transcrire(decoder_audio(audio.content))
        except subprocess.CalledProcessError:
            texte, score = "", 0.0  # audio illisible : la plateforme transmettra à l'équipe
        res = requests.post(
            f"{URL}/api/ia.php", params={"action": "resultat"}, headers=entetes(), timeout=30,
            json={"id": v["id"], "transcription": texte, "score": round(score, 4), "modele": transcrire.nom},
        )
        res.raise_for_status()
        n += 1
        print(f"vocal {v['id']} · {time.time() - debut:.1f} s · confiance {score:.2f} · {res.json().get('decision')} · « {texte} »", flush=True)
    return n


def main() -> None:
    if not (URL and JETON):
        sys.exit("Définissez MK_URL et MK_JETON_IA (voir l'en-tête de ce fichier).")
    print(f"Chargement du modèle {MODELE}…", flush=True)
    transcrire = Transcripteur(MODELE)
    print(f"Prêt ({transcrire.appareil}). Interrogation de {URL} toutes les {INTERVALLE:.0f} s.", flush=True)
    while True:
        try:
            une_passe(transcrire)
        except Exception as e:  # une erreur ponctuelle ne doit jamais arrêter le service
            print(f"Erreur : {e}", file=sys.stderr, flush=True)
        if "--boucle" not in sys.argv:
            break
        time.sleep(INTERVALLE)


if __name__ == "__main__":
    main()
