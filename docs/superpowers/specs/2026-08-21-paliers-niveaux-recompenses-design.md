# Paliers unifiés (objectif + niveau + récompense) — design

Date: 2026-08-21
Statut: approuvé par l'utilisateur (chat), en attente de revue du fichier

## Contexte

Aujourd'hui, "niveaux" et "récompenses" des programmes de fidélité sont deux
systèmes **totalement indépendants**, par choix documenté explicite
(`MivaFid-doc/fidelite.md`, `recompense.md`) :

- **Récompenses** (`loyalty_programs.config['rewards']`) : paliers en cycle
  répété — objectif atteint → récompense débloquée → compteur remis à zéro,
  reparcourt le même espacement (ou le dernier espacement configuré) à
  l'infini. Calculé par `RewardTierService`.
- **Niveaux** (`loyalty_programs.config['levels']`) : seuils cumulés **à
  vie**, jamais remis à zéro, calculés sur les cycles complétés (ou le
  cashback cumulé à vie pour le type cashback). Calculé par
  `LoyaltyLevelService`. Aucun champ ne relie un niveau à une récompense.

Aucune table dédiée n'existe — tout est en JSON dans la colonne
`loyalty_programs.config`. `LoyaltyReward` (récompense réellement débloquée
par un client) n'a aucune colonne pour tracer quel palier/niveau l'a
débloquée.

L'utilisateur veut fusionner ces deux axes en un seul concept de **palier** :
un palier = un objectif (seuil cumulatif à vie) + un niveau (nom personnalisé
par le marchand) + une récompense, le tout débloqué ensemble quand le seuil
est atteint. Ceci **inverse volontairement** la règle d'indépendance
documentée actuelle — c'est un changement de paradigme assumé, pas un bug à
corriger.

## Décisions validées avec l'utilisateur

1. Remplacement complet du cycle répété par une progression **cumulative à
   vie** à paliers fixes (ex. 500 / 1000 / 2000 points), pour tous les types
   de programme (stamps, spend, cashback).
2. Une fois le dernier palier atteint, le client reste au niveau max — aucune
   récompense supplémentaire automatique tant que le marchand n'ajoute pas de
   palier.
3. Programmes existants : migration automatique best-effort (fusion des
   `rewards[]`/`levels[]` existants par index).
4. Icônes de niveau : automatiques par rang, jamais choisies par le marchand
   — séquence fixe 🥉🥈🥇💎👑 (paliers 1 à 5), puis ⭐ pour tout palier au-delà
   du 5e.
5. Programme à un seul palier configuré : pas de système de niveau affiché,
   uniquement objectif + récompense.

## Modèle de données

### Nouvelle table `loyalty_program_tiers`

```
id
loyalty_program_id  FK -> loyalty_programs, cascadeOnDelete
order                unsignedSmallInteger  -- 1-based, unique par programme
goal                 unsignedInteger       -- seuil cumulatif à vie
level_name           string                -- nom libre du marchand
reward_description   string
validity_days        unsignedInteger nullable
timestamps
```

Contrainte : `unique(loyalty_program_id, order)`, `goal` strictement
croissant par programme (validé en appli, pas en contrainte DB — cohérent
avec la validation actuelle de `StoreLoyaltyProgramRequest`).

Remplace entièrement `config['rewards']` et `config['levels']`. Les clés
`config['goal']`/`config['reward_description']` (fallback mono-palier
legacy) disparaissent — un programme mono-palier devient simplement un
programme avec une seule ligne `loyalty_program_tiers`.

### `loyalty_rewards` — nouvelle colonne

```
program_tier_id  FK -> loyalty_program_tiers, nullOnDelete, nullable
```

Trace quel palier a débloqué cette récompense. Nullable pour les récompenses
historiques migrées sans correspondance fiable.

### Métrique de progression (cumul à vie)

Pas de nouvelle colonne dénormalisée sur `loyalty_cards`. Calculée à la
volée par somme sur `loyalty_transactions`, exactement comme
`LoyaltyLevelService::lifetimeCashback()` le fait déjà pour le cashback :

- Types `stamps`/`spend` : `SUM(amount)` sur les transactions de type
  `stamp` (ou équivalent) pour la carte — remplace `completedCycles()`
  (qui comptait des cycles, pas des unités).
- Type `cashback` : inchangé, `SUM(amount)` sur les transactions
  `cashback_earn`.

`loyalty_cards.progress['stamps_current']` devient obsolète pour le calcul
du palier/niveau (il n'y a plus de cycle à faire progresser) — la colonne
reste en base (pas de migration destructive) mais n'est plus le
compteur de référence.

## Service unifié : `LoyaltyTierService`

Remplace `RewardTierService` et `LoyaltyLevelService` (fusion, suppression
des deux anciennes classes).

```php
tiers(LoyaltyProgram $program): Collection<Tier>   // triées par order
lifetimeMetric(LoyaltyCard $card): int             // somme transactions selon type
resolve(LoyaltyCard $card): array {
    'tiers' => [...],           // chaque tier + status: reached|current|upcoming, icon
    'current_tier' => Tier|null,
    'next_tier' => Tier|null,
    'percent_to_next' => int,   // 0-100, null si is_max_level
    'is_max_level' => bool,
    'level_name' => string|null,  // null si un seul palier configuré
}
unlockNewTiers(LoyaltyCard $card, int previousMetric, int newMetric): array<LoyaltyReward>
    // tiers dont goal est franchi entre previousMetric et newMetric ET
    // n'ayant pas déjà de LoyaltyReward pour (card, tier) -> crée les
    // LoyaltyReward manquantes (gère le franchissement multiple en un appel)
```

Icône : fonction pure `iconForRank(int order): string` — `['🥉','🥈','🥇','💎','👑']`
indexé, `'⭐'` par défaut au-delà.

## Progression / déblocage (`MerchantDashboardController::grantStampOrPoints`)

1. Enregistrer la transaction de grant (inchangé).
2. `previousMetric = LoyaltyTierService::lifetimeMetric($card)` avant le
   grant, `newMetric` après (ou calculer la transaction non encore committée
   + delta, selon ce qui est le plus simple dans la transaction DB).
3. `LoyaltyTierService::unlockNewTiers($card, $previousMetric, $newMetric)` —
   remplace la boucle `while` actuelle sur `cycle_completed`. Plus besoin
   d'insérer de transaction `cycle_completed` (l'ancien signal technique de
   comptage de cycles disparaît avec le cycle lui-même).
4. Dispatch `LoyaltyCardUpdated` (payload étendu, voir plus bas), puis
   `LoyaltyRewardUpdated` pour chaque récompense créée.

## Accesseurs `LoyaltyCard`

`getLevelAttribute()` et `getGoalAttribute()`/`getPercentAttribute()`
délèguent désormais à `LoyaltyTierService::resolve($this)` au lieu des deux
anciens services. Signature de sortie compatible avec l'existant côté API
(`level`, `goal`, `percent`) + nouveau champ `tiers` exposé sur la ressource
carte (pour la roadmap client).

## Événements temps réel

- `LoyaltyCardUpdated::broadcastWith()` : ajoute `tiers` (array complet avec
  status/icon) au payload existant.
- `LoyaltyRewardUpdated::broadcastWith()` : ajoute `program_tier_id`,
  `level_name`, `icon` (résolus via la relation `programTier`).

## API marchand

### `StoreLoyaltyProgramRequest`

`tiers[]` remplace `rewards[]` + `levels[]` :

```
tiers                          array, required, min:1
tiers.*.goal                   integer, required, strictement croissant
tiers.*.level_name             string, required (ignoré côté UI si tiers.count()===1)
tiers.*.reward_description     string, required
tiers.*.validity_days          integer, nullable
```

### `LoyaltyProgramController::store()`

Remplace les deux `updateOrCreate` JSON par : suppression des lignes
`loyalty_program_tiers` existantes du programme (si mise à jour) +
insertion des nouvelles, dans la même transaction que la mise à jour de
`loyalty_programs`.

## Migration des programmes existants

Commande Artisan one-shot (`php artisan loyalty:migrate-tiers`), exécutée une
fois en déploiement, PAS une migration de schéma classique (car logique
métier, pas juste DDL) :

Pour chaque `LoyaltyProgram` :
- Lire `config['rewards']` et `config['levels']`.
- Si `count(rewards) === count(levels)` : fusion index par index —
  `order=i+1, goal=rewards[i].goal, level_name=levels[i].name,
  reward_description=rewards[i].reward_description,
  validity_days=rewards[i].validity_days`.
- Sinon (comptage différent, ou `levels` absent) : baser sur `rewards` seul,
  `level_name = "Palier " . (i+1)` en fallback (le marchand renomme ensuite
  dans le nouvel écran unifié).
- Programme mono-palier legacy (`config['goal']`/`['reward_description']`
  seuls, pas de tableau `rewards`) : une seule ligne tier,
  `level_name = "Palier 1"`.
- `loyalty_rewards` existantes : `program_tier_id` laissé `null` (pas de
  correspondance fiable rétroactive — le `title` snapshotté reste la seule
  trace, comme aujourd'hui).

## Frontend marchand (Flutter)

Un seul modèle `ProgramTier { order, goal, levelName, rewardDescription,
validityDays }` remplace `RewardTier` + `LoyaltyLevel`.

Un seul écran/widget éditeur (utilisé à la fois dans l'onboarding
`merchant_step2_screen.dart` et dans les réglages, remplaçant
`ProgrammeRewardsScreen` + `ProgrammeLevelsScreen` qui sont supprimés) :
liste de lignes réordonnables, chaque ligne = objectif + nom du niveau
(champ masqué si une seule ligne existe) + récompense + validité optionnelle.
Validation cliente : objectifs strictement croissants avant soumission.

## Frontend client (Flutter)

- `LoyaltyCard` (modèle) : ajoute `tiers: List<CardTier>` (order, goal,
  levelName, rewardDescription, icon, status: reached/current/upcoming),
  peuplé depuis le nouveau champ API `tiers` et rafraîchi par
  `applyRealtimeUpdate()`.
- `card_face_content.dart` `_levelRow()` : inchangé dans sa structure,
  ajoute juste l'icône (`iconForRank`) devant le nom du niveau. Ne s'affiche
  toujours pas si `levelName == null` (cas mono-palier).
- `card_detail_screen.dart` : la liste plate actuelle de récompenses
  (`_DetailedRewardCard` en grille horizontale) est remplacée par une
  roadmap verticale des paliers : `Palier 1 🥉 ✓ → Palier 2 🥈 ✓ → Palier 3
  🥇 ●` (palier courant marqué différemment), chaque nœud affichant
  objectif/niveau/récompense, tap sur un palier atteint ouvrant le popup de
  détail récompense (fonctionnalité déjà en cours d'implémentation dans
  cette même conversation, sera raccordée à la roadmap plutôt qu'à
  l'ancienne liste plate).

## Tests impactés

- `AddStampTest::test_level_progresses_with_lifetime_completed_cycles` :
  preuve actuelle d'indépendance niveau/récompense — **doit être réécrit**,
  le comportement attendu est maintenant l'inverse (niveau ET récompense
  liés au même palier).
- `AddStampTest::test_overflow_past_goal_is_carried_over_not_discarded` /
  `test_single_grant_can_unlock_multiple_rewards_at_once` : adaptés au
  nouveau mécanisme de franchissement multi-paliers (plus de notion de
  "span"/cycle qui se répète, juste des seuils fixes cumulatifs).
- `LoyaltyProgramCreationTest` : étendu pour couvrir la validation `tiers[]`.
- Nouveaux : `LoyaltyTierServiceTest` (résolution palier courant, icônes,
  franchissement multiple, cas mono-palier), test de la commande de
  migration best-effort.

## Hors scope (explicitement, YAGNI)

- Pas de sélection d'icône par le marchand (auto par rang, décidé).
- Pas de "second cycle" après le dernier palier (décidé : palier max
  maintenu, rien de plus).
- Pas de changement au flux de scan/validation de récompense
  (`RewardRedemptionTest`) — uniquement la façon dont la récompense est
  débloquée et rattachée à un palier change, pas son cycle de vie une fois
  créée.
