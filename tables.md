# Liste des tables — Application de Fidélité

## 1. plans
**Utilité** : Définit les offres d'abonnement SaaS (Starter, Pro, Enterprise) et leurs limites.
**Attributs** : id, name, slug, price_monthly, price_yearly, max_staff, max_loyalty_programs, max_clients, allows_cashback, allows_vip, allows_auto_notifications, allows_geolocation, allows_marketplace, is_active
**Relations** : 1,n vers `restaurants` · 1,n vers `subscriptions`

## 2. restaurants
**Utilité** : Compte d'un établissement partenaire (le "client business" de la plateforme).
**Attributs** : id, name, email, phone, password, plan_id (FK), logo_url, address, city, location (PostGIS Point), qr_token, status
**Relations** : n,1 vers `plans` · 1,n vers `subscriptions`, `staff_users`, `loyalty_programs`, `loyalty_cards`, `notification_campaigns`, `notification_logs`, `client_restaurant_geo_optins`, `action_logs`, `restaurant_affinities`

## 3. subscriptions
**Utilité** : Historique des abonnements d'un restaurant (renouvellements, changements de plan).
**Attributs** : id, restaurant_id (FK), plan_id (FK), status, billing_cycle, starts_at, ends_at, canceled_at, payment_reference
**Relations** : n,1 vers `restaurants` · n,1 vers `plans`

## 4. staff_users
**Utilité** : Comptes du personnel d'un restaurant (owner, manager, staff) qui valident les actions fidélité.
**Attributs** : id, restaurant_id (FK), name, email, phone, password, role, is_active
**Relations** : n,1 vers `restaurants` · 1,n vers `loyalty_transactions` (validation/annulation), `action_logs`

## 5. clients
**Utilité** : Compte client final, unique pour toute la plateforme (une app pour tous les restos).
**Attributs** : id, first_name, phone, password, birthdate, city, avatar_url, referral_code, referred_by_client_id (FK), fcm_token, phone_verified_at
**Relations** : 1,n vers `loyalty_cards`, `notification_logs`, `client_restaurant_geo_optins`, `referrals` (parrain/filleul) · n,1 vers `clients` (auto-référence parrainage)

## 6. super_admins
**Utilité** : Comptes de l'équipe Anthropic-side SaaS (support, modération, gestion globale).
**Attributs** : id, name, email, password, role
**Relations** : aucune FK — agit transversalement sur toute la plateforme via l'application (pas de lien relationnel direct)

## 7. loyalty_programs
**Utilité** : Mécanique de fidélité configurée par un restaurant (tampons, points, cashback ou VIP).
**Attributs** : id, restaurant_id (FK), name, type (stamp/points/cashback/vip), is_active, config (JSON)
**Relations** : n,1 vers `restaurants` · 1,n vers `loyalty_cards`

## 8. loyalty_cards
**Utilité** : Carte fidélité d'un client pour un programme précis d'un restaurant — porte l'état courant (progression, solde).
**Attributs** : id, client_id (FK), restaurant_id (FK), loyalty_program_id (FK), card_code, qr_token, progress (JSON), cashback_balance_fcfa, vip_tier, status, last_activity_at
**Relations** : n,1 vers `clients`, `restaurants`, `loyalty_programs` · 1,n vers `loyalty_transactions`

## 9. loyalty_transactions
**Utilité** : Historique append-only de chaque action fidélité (+1 tampon, +10 points, cashback crédité...) — base anti-fraude.
**Attributs** : id, loyalty_card_id (FK), staff_user_id (FK), type, value, montant_commande_fcfa (optionnel, saisi par le staff pour calculer points/cashback), validation_method, status, canceled_by_staff_user_id (FK), canceled_at, meta (JSON)
**Relations** : n,1 vers `loyalty_cards`, `staff_users`

## 10. notification_campaigns
**Utilité** : Campagne de notification créée par un restaurant, manuelle (événement, promo) ou automatique (anniversaire, inactivité...).
**Attributs** : id, restaurant_id (FK), title, message, kind (manual/auto), trigger_type, target (JSON), scheduled_at, sent_at, status
**Relations** : n,1 vers `restaurants` · 1,n vers `notification_logs`

## 11. notification_logs
**Utilité** : Trace d'envoi individuel d'une notification à un client (statut FCM par destinataire).
**Attributs** : id, notification_campaign_id (FK), client_id (FK), restaurant_id (FK), channel, status, failure_reason, sent_at
**Relations** : n,1 vers `notification_campaigns`, `clients`, `restaurants`

## 12. client_restaurant_geo_optins
**Utilité** : Consentement et paramètres de géolocalisation d'un client pour un restaurant donné (notif "vous êtes proche de...").
**Attributs** : id, client_id (FK), restaurant_id (FK), opted_in, radius_m, last_notified_at
**Relations** : n,1 vers `clients` · n,1 vers `restaurants`

## 13. referrals
**Utilité** : Suivi du système de parrainage entre clients (bonus parrain/filleul).
**Attributs** : id, referrer_client_id (FK), referred_client_id (FK), restaurant_id (FK), status, referrer_bonus_points, referred_bonus_points, rewarded_at
**Relations** : n,1 vers `clients` (x2 : parrain et filleul) · n,1 vers `restaurants`

## 14. restaurant_affinities
**Utilité** : Score d'affinité précalculé entre deux restaurants, pour le moteur de recommandation marketplace ("les clients de X aiment aussi Y").
**Attributs** : id, restaurant_id (FK), related_restaurant_id (FK), score, computed_at
**Relations** : n,1 vers `restaurants` (x2)

## 15. action_logs
**Utilité** : Journal d'audit des actions sensibles côté restaurant (annulation, suspension staff, changement de permission).
**Attributs** : id, restaurant_id (FK), staff_user_id (FK), action, target_type, target_id, meta (JSON)
**Relations** : n,1 vers `restaurants`, `staff_users`

## 16. personal_access_tokens
**Utilité** : Tokens d'authentification API (Sanctum) pour tous les types de comptes (restaurants, staff, clients, super admins).
**Attributs** : id, tokenable_type, tokenable_id, name, token, abilities, last_used_at, expires_at
**Relations** : polymorphe vers `restaurants`, `staff_users`, `clients`, `super_admins`

## 17. roles / permissions (Spatie)
**Utilité** : Gestion fine des rôles et permissions du staff (owner, manager, staff + permissions custom).
**Attributs** : roles(id, name, guard_name), permissions(id, name, guard_name) + tables pivot model_has_roles, model_has_permissions, role_has_permissions
**Relations** : polymorphe vers `staff_users` (principalement)
