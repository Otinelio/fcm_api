# Équipe marchand — rôles Administrateur / Opérateur

## Contexte et objectif

Le compte marchand actuel (`Restaurant`, un seul login par établissement) ne
permet pas de distinguer qui, dans l'équipe d'un commerce, effectue une
opération de fidélité. La table `staff_users` existe déjà en base
(migration `2026_07_20_000004_create_staff_users_table.php`), tout comme les
colonnes d'attribution `loyalty_transactions.staff_user_id`,
`loyalty_rewards.used_by_staff_user_id` et
`loyalty_rewards.canceled_by_staff_user_id` — mais rien dans le code
applicatif (Model, Controller, Route) ne les utilise. L'écran
`team_screen.dart` côté Flutter est entièrement factice (liste statique en
mémoire, aucun appel API).

Objectif : construire un vrai système à **exactement deux rôles**.

- **ADMINISTRATEUR** — accès complet (identique à ce qu'un compte `Restaurant`
  a aujourd'hui) : profil, programme de fidélité, paliers, équipe,
  statistiques, campagnes.
- **OPÉRATEUR** — accès strictement opérationnel : scanner un QR, effectuer
  les opérations prévues par le programme (tampon, achat, cashback,
  récompense), rien d'autre.

Chaque opérateur a son propre compte (email + mot de passe), sur son propre
appareil, en session permanente comme aujourd'hui pour un compte marchand.
Aucun troisième rôle. Aucune permission personnalisée par fonctionnalité —
la séparation est binaire.

**Hors périmètre** : règles métier des programmes de fidélité (paliers,
calcul cashback, etc.) — inchangées. Suppression de compte équipe (seule la
désactivation est prévue). Auth multi-appareils/partagés.

## Architecture

### Principe central : token Sanctum émis au nom du `Restaurant`

Un `StaffUser` ne s'authentifie pas via son propre guard Sanctum. Quand il
se connecte, le backend vérifie ses identifiants dans `staff_users`, retrouve
son `Restaurant` parent, puis émet un token **appartenant au `Restaurant`**
(`$restaurant->createToken(...)`), avec l'identité de l'opérateur encodée
dans les **abilities** du token : `["staff:{$staffUser->id}"]`.

Conséquence directe : `$request->user()` continue de résoudre vers
l'instance `Restaurant` sur **toutes** les routes existantes, sans exception
et sans modification. C'est le choix qui permet de ne rien casser dans les
~20 méthodes de contrôleur qui font aujourd'hui `$request->user()` en
supposant un `Restaurant`.

Un compte `Restaurant` classique (login normal, pas de staff) reste
implicitement ADMINISTRATEUR — comportement identique à aujourd'hui, aucun
changement.

### `CurrentActor` — résolution de l'acteur courant

Nouvelle classe `app/Support/CurrentActor.php` :

```php
final class CurrentActor
{
    public static function resolve(Request $request): array
    {
        // ['type' => 'restaurant', 'staff' => null, 'role' => 'admin']
        // ou
        // ['type' => 'staff', 'staff' => StaffUser, 'role' => 'admin'|'operator']
    }
}
```

Lit `$request->user()->currentAccessToken()->abilities`, cherche une entrée
`staff:{id}`. Si trouvée, charge le `StaffUser` (id caché dans l'ability) et
retourne son `role`. Sinon, l'acteur est le propriétaire du compte
(`role` = `admin`). Utilisé :

1. Dans le nouveau middleware `admin.only` (permissions).
2. Aux ~5 points d'écriture qui doivent enregistrer `staff_user_id`.

Un `StaffUser` inactif (`is_active = false`) fait échouer la résolution
avec une exception dédiée → 401, même si le token est encore
techniquement valide (effet immédiat d'une désactivation, voir plus bas).

### Rôles et permissions

`staff_users.role` est contraint à deux valeurs : `admin` ou `operator`
(remplace le commentaire de colonne existant "owner, manager, staff", jamais
implémenté). Un `StaffUser` avec `role = admin` a exactement les mêmes
droits qu'un compte `Restaurant` — l'établissement peut donc avoir plusieurs
administrateurs.

Nouveau middleware `admin.only` (alias enregistré dans `bootstrap/app.php`),
appliqué aux routes suivantes (déplacées sous ce middleware, sans changer
leur contrôleur/logique) :

- `PUT /auth/merchant/profile`, `/profile/logo` (POST/DELETE), `/plan`
- `POST /loyalty-programs` (création/édition programme + paliers)
- `GET /merchant/stats`
- `GET /merchant/clients` (liste/recherche clientèle)
- `POST/GET /merchant/campaigns*` (SMS)
- `GET/POST/PUT/PATCH /auth/merchant/team*` (gestion équipe)

Restent ouvertes aux deux rôles (aucun changement) :

- Scan / lookup d'une carte client (`GET /merchant/clients/{id}`,
  recherche par code)
- `POST /merchant/clients/{id}/stamps` (tampon/achat/crédit cashback)
- `POST /merchant/clients/{id}/redeem-cashback`
- Scan/validation/annulation de récompense
- **Nouveau** : `GET /merchant/clients/{id}/history` (voir ci-dessous) —
  accessible aux deux rôles, décision confirmée en amont.

Le middleware retourne un message simple, non technique : `"Réservé à
l'administrateur."` (422/403).

### Attribution des opérations

Aux points d'écriture existants (`MerchantDashboardController` :
`grantStampOrPoints`/`grantCashback`, `redeemCashback`, validation/
annulation de récompense), une seule ligne ajoutée par site : résoudre
`CurrentActor::resolve($request)` et, si c'est un `StaffUser`, remplir
`staff_user_id` (`loyalty_transactions`) ou `used_by_staff_user_id`/
`canceled_by_staff_user_id` (`loyalty_rewards`). Aucune autre logique
métier touchée — pas de changement de calcul, de verrou, de validation.

### Historique consultable (nouveau, les deux rôles)

`GET /api/merchant/clients/{loyaltyCard}/history` (nouveau — le
`client_detail_screen.dart` marchand a déjà une icône "historique" jamais
câblée). Réutilise le même `SELECT` que l'historique client existant
(`LoyaltyCardController::history`), en y ajoutant la jointure vers
`staff_users` pour exposer `staff_name`/`staff_role` (`null` = effectué par
l'administrateur/propriétaire directement).

### Gestion d'équipe (admin uniquement)

- `GET /auth/merchant/team` — liste (id, name, email, phone, role,
  is_active, created_at).
- `POST /auth/merchant/team` — créer (name, email unique, phone?,
  password, role ∈ {admin, operator}).
- `PUT /auth/merchant/team/{staffUser}` — éditer name/phone/role, et
  optionnellement réinitialiser le mot de passe (l'admin fixe un nouveau
  mot de passe directement — pas de flux d'invitation par e-mail, décision
  actée en amont). Scopé au `restaurant_id` courant (404 sinon, jamais de
  fuite cross-tenant).
- `PATCH /auth/merchant/team/{staffUser}/toggle-active` — active/désactive.
  À la désactivation : révoque tout token du `Restaurant` dont l'ability
  contient `staff:{id}` (recherche dans `personal_access_tokens` par
  `tokenable_id` + ability, pas de relation directe `StaffUser`→tokens
  puisque les tokens appartiennent au `Restaurant`).
- Pas d'endpoint de suppression dure (hors périmètre, cf. cahier des
  charges : "inviter, désactiver, modifier" seulement).

## Frontend (Flutter)

### Connexion

Écran de login marchand existant : ajout d'une bascule "Administrateur" /
"Opérateur" (deux formulaires email+mot de passe, deux endpoints). Défaut :
Administrateur.

### Modèle de compte

`RestaurantAccount` gagne trois champs : `actorType` (`restaurant`|`staff`),
`staffName` (`String?`), `staffRole` (`String?`, `admin`|`operator`|`null`).
Provider dérivé `isAdminProvider` = `actorType == 'restaurant' ||
staffRole == 'admin'`.

### Navigation

`MerchantShell` lit `isAdminProvider` :

- **Opérateur** : atterrit directement sur `/merchant/validate` après
  connexion. Barre d'onglets réduite à Validate + un menu compte minimal
  (changer mot de passe, déconnexion — réutilise l'écran déjà construit).
  Dashboard, Clients, SMS, Plus (programme/paramètres/équipe) totalement
  absents de la navigation, pas seulement grisés.
- **Admin** : navigation actuelle inchangée, à l'identique.

Garde-fou supplémentaire au niveau du router (`redirect:`) : une tentative
d'URL directe vers une route admin-only par un opérateur renvoie vers
`/merchant/validate` plutôt que d'afficher un écran cassé (défense en
profondeur, le backend refuse de toute façon la requête).

### Écran Équipe

`team_screen.dart` reconstruit entièrement : liste réelle (`GET /team`),
formulaire d'invitation (nom, email, téléphone optionnel, mot de passe,
sélecteur de rôle limité aux deux valeurs), bascule actif/inactif, édition.
Remplace intégralement le mock actuel.

### Historique

`client_detail_screen.dart` (marchand) : l'icône "historique" déjà présente
mais jamais câblée devient fonctionnelle, appelle le nouvel endpoint,
affiche chaque ligne avec "Effectué par : {nom} — {Administrateur|
Opérateur}" (ou rien si `staff_name` est `null`, l'action vient du
propriétaire).

## Erreurs

Messages simples, jamais techniques, cohérents avec le pattern déjà en
place dans le reste de l'app (`ErrorMessages`) :

- Refus admin-only : "Réservé à l'administrateur."
- Compte opérateur désactivé / identifiants invalides : mêmes messages
  génériques que le login marchand actuel (pas de distinction qui
  révélerait l'existence d'un compte).
- Email déjà utilisé dans l'équipe : "Cette adresse est déjà utilisée par
  un membre de l'équipe."

## Tests

**Backend** : login staff (succès, mot de passe faux, compte inactif),
`CurrentActor::resolve` (Restaurant nu, staff admin, staff opérateur, staff
inactif → exception), middleware `admin.only` (rejette un opérateur sur
chaque route listée, laisse passer un admin/staff-admin), attribution
`staff_user_id`/`used_by_staff_user_id` correcte sur tampon/cashback/
récompense quand l'acteur est un opérateur (absente quand c'est
l'administrateur), CRUD équipe complet (créer/lister/éditer/désactiver),
révocation de token effective immédiatement après désactivation même avec
un token déjà émis, scoping cross-tenant (un admin ne peut pas
lister/éditer l'équipe d'un autre restaurant), endpoint historique
(retourne `staff_name` correct, `null` pour une action admin).

**Frontend** : parsing des nouveaux champs `RestaurantAccount`, valeur de
`isAdminProvider` selon les combinaisons `actorType`/`staffRole`.

## Migration des données existantes

Aucune donnée historique à migrer : `staff_user_id` et les colonnes liées
sont `nullable`, déjà en base, jamais peuplées — un déploiement sans
downtime, sans backfill nécessaire.
