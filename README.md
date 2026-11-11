# Musow Kènèya

Voice assistant that answers maternal and reproductive health questions in Dioula,
with answers written and validated by a physician. Prototype by NOUR TECH (Côte d'Ivoire).

- `plateforme/`: PHP/MySQL web platform (team dashboard, question library,
  medical validation, WhatsApp Cloud API connection, danger alerts).
- `ia/`: Dioula speech recognition service (MMS), question evaluation tool,
  Windows installation scripts.

Configuration files containing secrets are not included:
copy `config.example.php` and `ia/configuration.exemple.bat` and fill in your own values.

Licence: MIT.
