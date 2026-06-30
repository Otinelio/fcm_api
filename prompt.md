MODULE 11 — Adapter ton infrastructure de production existante à la stratégie hybride

1. Objectif du module

Vérifier et ajuster ta configuration Nginx, Supervisor et Redis existante pour qu'elle prenne correctement en charge la stratégie hybride construite dans cette formation — sans rien réinstaller depuis zéro.

2. Concept à comprendre

Contrairement à un déploiement classique, tu n'installes rien ici : Nginx, Supervisor et Redis tournent déjà selon ton bilan d'architecture. Le travail de ce module est différent — c'est un audit ciblé sur trois points précis que cette formation introduit et que ta config existante n'a probablement pas encore prévus, parce qu'ils sont spécifiques à RewardUnlocked et à SendRewardFcmFallback :

1. Nginx et le WebSocket upgrade. Ton Nginx route déjà le trafic vers Reverb sur le port 8080 — mais cette formation ajoute un appel HTTP classique (non-WebSocket) vers ce même port, depuis PresenceChecker (Module 3). Si ton bloc Nginx pour Reverb force un Upgrade de connexion sur tout le chemin, ce n'est pas un problème puisque cet appel-là contourne déjà Nginx (Module 3, appel direct en 127.0.0.1:8080) — mais c'est l'occasion de vérifier que ton bloc Nginx existant ne route bien que le trafic public des clients Flutter, et que rien de cette formation ne dépend de lui pour fonctionner en interne.

2. Supervisor et le rechargement de code. Ton worker de queue tourne en continu depuis avant cette formation. Chaque job introduit ici (SendRewardFcmFallback) sera chargé en mémoire par ce worker au moment où tu le modifies pour la dernière fois dans les Modules 6 et 9 — pas avant. Le réflexe queue:restart mentionné dans ton bilan n'est pas une formalité administrative, c'est littéralement la seule façon que Supervisor a de savoir "recharge le code PHP, ne garde pas l'ancienne version en mémoire".

3. Redis et la cohabitation cache/queue/sessions. Ton bilan précise que cache, queues et sessions partagent maintenant Redis. Les clés reward_acked:* et reward_fcm_lock:* (Modules 5 et 9) vivent dans le même Redis que tes sessions utilisateur et tes jobs en attente. Ce n'est pas un problème en soi (Laravel préfixe ses clés correctement par défaut), mais ça veut dire qu'un redis-cli FLUSHALL malheureux en debug ferait disparaître à la fois les sessions actives, les jobs en attente ET tes clés d'ack/idempotence — un seul geste imprudent, trois systèmes touchés. À garder en tête avant de "nettoyer Redis rapidement" en environnement de prod.

3. Actions exactes à réaliser


Vérifier le bloc Nginx existant pour Reverb et confirmer qu'il ne route que le trafic WebSocket public, sans impacter l'appel interne du Module 3.
Ajouter php artisan queue:restart à ta checklist de déploiement chaque fois que tu touches à SendRewardFcmFallback ou NotificationDispatcher.
Confirmer dans .env que CACHE_STORE=redis et QUEUE_CONNECTION=redis sont bien actifs (pas seulement supposés).
Planifier le rapport de santé (notifications:health-report) en cron Laravel, en plus de la supervision déjà existante de Reverb et du worker.
Tester la résilience du système hybride spécifiquement (pas juste "Reverb tourne", mais "le fallback FCM se déclenche après un restart du worker").


4. Fichiers à créer ou modifier


/etc/nginx/sites-available/restaurant-loyalty (vérification, pas création)
.env (vérification des variables CACHE_STORE et QUEUE_CONNECTION)
app/Console/Kernel.php (ajout de la planification du rapport de santé)
Ton script ou checklist de déploiement existant (ajout du queue:restart)


5. Commandes à exécuter

bash# Vérifier la config Nginx active pour Reverb
sudo nginx -T | grep -A 15 "location /app"

# Confirmer les drivers Redis actifs
php artisan tinker --execute="echo config('cache.default') . ' / ' . config('queue.default');"

# Vérifier que Supervisor connaît bien les deux process
sudo supervisorctl status

# Après toute modification de SendRewardFcmFallback ou NotificationDispatcher
php artisan queue:restart

6. Code à écrire

Extrait de bloc Nginx attendu pour Reverb (à comparer avec ton existant, pas à recréer) :

nginxlocation /app {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
}


Si ton bloc existant ressemble à ça, tu n'as rien à changer — c'est exactement pour ça que le Module 3 contourne ce chemin et appelle 127.0.0.1:8080 directement plutôt que de passer par cette route Nginx pensée pour des connexions WebSocket persistantes, pas pour de courts appels HTTP server-to-server.



Ajout de la planification dans app/Console/Kernel.php :

phpprotected function schedule(Schedule $schedule): void
{
    $schedule->command('notifications:health-report')
        ->dailyAt('08:00')
        ->emailOutputTo(config('mail.admin_address'));
}

Checklist de déploiement à compléter (texte, pas du code — à coller dans ton process de déploiement existant) :

[ ] Migrations exécutées (php artisan migrate --force)
[ ] php artisan config:cache
[ ] php artisan queue:restart   ← obligatoire si Jobs/Services de cette formation modifiés
[ ] sudo supervisorctl status   → reverb + queue-worker en RUNNING

7. Résultat attendu


Le bloc Nginx pour Reverb ne nécessite aucune modification (confirmé par l'audit, pas supposé).
config('cache.default') et config('queue.default') retournent bien redis.
queue:restart fait partie de ta routine de déploiement, pas d'une mémoire à entretenir manuellement.
Le rapport de santé quotidien est planifié et arrive bien par email.


8. Comment vérifier que ça fonctionne

Modifie volontairement une ligne de log dans SendRewardFcmFallback (ex: change le texte d'un Log::info), déploie sans faire queue:restart, déclenche un reward app fermée, et observe les logs : l'ancien texte apparaît encore. Fais ensuite queue:restart et redéclenche : le nouveau texte apparaît. Ce test, fait une fois consciemment, ancre le réflexe mieux qu'une checklist seule.

9. Erreurs courantes à éviter


Supposer que CACHE_STORE=redis est actif sans le vérifier réellement après un déploiement — un .env mal synchronisé entre environnements est la cause la plus fréquente de "ça marchait en local et plus en prod" sur ce genre de système.
Faire un redis-cli FLUSHALL en debug de prod pour "repartir propre" — ça vide aussi les sessions actives de tous les clients connectés et les jobs en attente, pas seulement les clés de cette formation. Préférer redis-cli --scan --pattern "reward_*" suivi de suppressions ciblées si un nettoyage est vraiment nécessaire.
Oublier que queue:restart ne redémarre pas immédiatement le worker — il pose un signal que le worker lit à la fin de son job en cours. S'il n'y a aucun job en cours, c'est instantané ; sinon, prévoir quelques secondes.
Continuer à croire que Supervisor "redéploie le code" — il ne fait que relancer un process mort. Le rechargement du code reste entièrement ta responsabilité via queue:restart.


10. Checklist de validation avant de passer au module suivant


 Le bloc Nginx pour Reverb a été audité et confirmé conforme (ou corrigé si besoin).
 cache.default et queue.default retournent bien redis en environnement de production réel (pas seulement en local).
 Le test "modification sans restart vs avec restart" a été fait une fois pour ancrer le réflexe.
 Le rapport de santé quotidien (notifications:health-report) est planifié et reçu par email au moins une fois.
 La checklist de déploiement existante inclut désormais explicitement queue:restart.