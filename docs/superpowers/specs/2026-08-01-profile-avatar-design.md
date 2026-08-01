# Gestion de l'avatar de profil — Design

Date : 2026-08-01
Statut : Approuvé, prêt pour le plan d'implémentation

## Contexte

Le champ `avatar_url` existe déjà sur `clients` (migration `2026_07_20_000005_create_clients_table.php`) et sur le modèle Flutter `User` (`photoUrl`), mais rien ne l'alimente ni ne l'affiche aujourd'hui — sauf l'OAuth Google/Apple (`SocialAuthService`, qui recopie `picture` dans `avatar_url` à l'inscription). L'avatar affiché sur `profile_screen.dart` (lignes 186-216) est toujours un cercle d'initiales généré côté client ; `photoUrl` n'est lu nulle part ailleurs dans le code Flutter.

Cette feature ajoute : upload manuel d'une photo par l'utilisateur, suppression pour revenir aux initiales, et l'affichage réel de l'avatar une fois défini.

Deux dépôts concernés :
- `restaurant-loyalty-api` (Laravel, backend)
- `fidelity_app` (Flutter, mobile)

## Décisions (validées avec l'utilisateur)

| Sujet | Choix |
|---|---|
| Source de la photo | Galerie + Appareil photo (bottom sheet à 2 options) |
| Recadrage | Écran de recadrage carré (1:1) côté client avant upload |
| Stockage backend | Disque local Laravel (`public` disk + `storage:link`), pas de service cloud |
| Suppression | Autorisée — retour à l'avatar par défaut (initiales) |

## Backend (Laravel)

### Endpoints

Ajoutés à `ClientAuthController`, sous le même groupe de middleware `auth:sanctum` + guard `clients` que les routes de profil existantes :

- `POST /api/auth/profile/avatar` — upload multipart
- `DELETE /api/auth/profile/avatar` — suppression

Pourquoi des routes dédiées plutôt qu'étendre `PUT /api/auth/profile` : cette route est JSON-only via `UpdateProfileRequest` ; mélanger multipart et JSON dans le même form request complique inutilement la validation. Deux routes fines et isolées valent mieux qu'une route qui fait deux choses.

### Validation

Nouvelle `UpdateAvatarRequest` (FormRequest) :
- `avatar` : required, `image`, `mimes:jpg,jpeg,png,webp`, `max:5120` (5 Mo), `dimensions:min_width=200,min_height=200`

### Stockage

- Disque `public` (`storage/app/public`), chemin déterministe `avatars/{client_uuid}.jpg`
- Un ré-upload écrase simplement l'ancien fichier (même nom) — pas de nettoyage de fichiers orphelins à gérer
- Prérequis d'infra : `php artisan storage:link` doit être exécuté (vérifié : `public/storage` n'existe pas encore sur cet environnement)
- `avatar_url` stocké en base = URL publique complète (`Storage::url(...)` résolu en absolu), cohérent avec ce que renvoie déjà `clientData()` pour les autres champs

### Suppression

`DELETE /api/auth/profile/avatar` : supprime le fichier du disque s'il existe, met `avatar_url` à `null`, renvoie le client à jour (même format que `clientData()`).

### Réponse

Même forme que `updateProfile` : `{ "message": "...", "client": { ...clientData... } }` — le front n'a pas besoin de gérer un format de réponse différent.

## Frontend (Flutter)

### Dépendances ajoutées

- `image_picker` — sélection caméra/galerie
- `image_cropper` — recadrage carré 1:1, avec `maxWidth`/`maxHeight`/`compressQuality` réglés pour que le fichier de sortie soit déjà léger (limite le besoin de retraitement serveur)

### Widget réutilisable `UserAvatar`

Extrait de la logique actuellement inline dans `profile_screen.dart:186-216` (cercle d'initiales). Le nouveau widget affiche `Image.network(photoUrl)` quand disponible, retombe sur les initiales sinon. Centralise la logique pour ne pas la dupliquer si l'avatar doit apparaître ailleurs plus tard.

### Point d'entrée

Tap sur l'avatar dans `profile_screen.dart` → bottom sheet stylée comme le sélecteur de pays existant (mêmes couleurs/rayon/handle), avec :
- Prendre une photo
- Choisir dans la galerie
- Supprimer la photo (affiché seulement si `photoUrl` non nul)
- Annuler

### Flux

1. Sélection image (`image_picker`) → recadrage carré (`image_cropper`)
2. État de chargement sur le cercle d'avatar — l'ancienne image reste visible pendant l'upload (pas de flash vers un placeholder)
3. Upload multipart via `dio` vers `POST /api/auth/profile/avatar`
4. Succès → mise à jour de l'état utilisateur Riverpod avec le nouvel `avatar_url` reçu
5. Suppression : confirmation → `DELETE /api/auth/profile/avatar` → `photoUrl` mis à `null` côté état → retour aux initiales

### Permissions natives

- Android : permissions caméra + accès images média dans le manifest
- iOS : `NSCameraUsageDescription` / `NSPhotoLibraryUsageDescription` dans `Info.plist`

Facile à oublier, à vérifier explicitement dans le plan d'implémentation.

## Gestion des erreurs

- Erreur de validation serveur (mauvais type / trop lourd) → mappée via `error_messages.dart` existant
- Échec réseau/upload → snackbar, avatar inchangé (pas de mise à jour optimiste avant confirmation serveur)
- Permission caméra/galerie refusée → message invitant à activer la permission dans les réglages système

## Tests

- Backend : test Feature sur les deux endpoints (`UploadedFile::fake()->image()`) — vérifie fichier présent sur le disque + `avatar_url` dans la réponse, validation rejette un fichier invalide/trop gros, requête non authentifiée rejetée. Suit la convention `composer test` déjà en place dans ce repo.
- Frontend : pas de précédent de tests widget dans ce repo (seul `widget_test.dart` boilerplate présent) — validation prévue par passage manuel via la skill `run`, pas de nouveaux tests automatisés.

## Hors scope

- Redimensionnement/compression côté serveur (GD est disponible mais le crop client + compression `image_cropper` suffisent pour le MVP)
- Affichage de l'avatar ailleurs que `profile_screen.dart` (aucun autre écran ne lit `photoUrl` aujourd'hui)
- Modération de contenu / vérification automatique des photos uploadées
