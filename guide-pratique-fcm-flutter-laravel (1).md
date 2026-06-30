# Guide d'Accompagnement Pratique — FCM avec Flutter + Laravel
## Restaurant Loyalty Notification System

---

## Comment utiliser ce guide

Ce n'est pas un guide théorique. À chaque étape tu trouveras trois choses :

- **🔧 Action** — ce que tu dois faire, précisément (commande, fichier, code).
- **✅ Résultat attendu** — ce que tu dois observer pour savoir que c'est bon, avant de continuer.
- **⚠️ Erreurs à éviter** — les pièges classiques à cet endroit précis.

**Ce qui reste entièrement à toi** : le design, les couleurs, la mise en page, le choix des widgets visuels. Ce guide ne touche jamais à ça — il s'occupe uniquement de la plomberie technique FCM (configuration, tokens, envoi, réception, logique).

**Règle d'or** : ne passe au module suivant que si le ✅ *Résultat attendu* du module en cours est réellement observé chez toi. Pas "ça devrait marcher" — vérifié.

---

## MODULE 1 — Comprendre l'écosystème (avant de coder)

**🔧 Action** : avant d'ouvrir ton IDE, réponds par écrit (papier ou note) à ces 4 questions :
1. Qui décide d'envoyer une notification ? (Laravel)
2. Qui transporte le message jusqu'au téléphone ? (Firebase)
3. Qui reçoit le message au niveau système ? (l'OS du téléphone, même app fermée)
4. Qui décide quoi afficher / quoi faire avec ce message ? (ton code Flutter)

```
Laravel (décide) → Firebase (transporte) → OS du device (reçoit) → Flutter (réagit)
```

**✅ Résultat attendu** : tu es capable d'expliquer ce flow à voix haute, sans relire, en moins de 30 secondes.

**⚠️ Erreur à éviter** : sauter ce module parce que "c'est évident". 80% des bugs FCM viennent d'une confusion entre ces 4 rôles (ex: chercher un bug Flutter alors que le problème est une mauvaise config Firebase côté serveur).

---

## MODULE 2 — Configuration de l'environnement (étape par étape)

### 2.1 — Créer le projet Firebase

**🔧 Action**
1. Va sur [console.firebase.google.com](https://console.firebase.google.com) → **Ajouter un projet**.
2. Nomme-le (ex: `restaurant-loyalty-app`). Note bien l'**ID du projet** affiché (différent du nom) — tu en auras besoin partout.
3. Désactive Google Analytics si tu n'en as pas besoin tout de suite (tu peux l'ajouter plus tard).

**✅ Résultat attendu** : tu arrives sur le dashboard du projet Firebase vide, avec son ID visible dans **Paramètres du projet ⚙️**.

**⚠️ Erreur à éviter** : confondre le **nom du projet** (`Restaurant Loyalty App`) et l'**ID du projet** (`restaurant-loyalty-app-a1b2c`). C'est l'ID qui sert dans les URLs de l'API et dans tes fichiers de config — pas le nom affiché.

---

### 2.2 — Ajouter l'app Android à Firebase

**🔧 Action**
1. Dans Firebase Console → **Ajouter une application → Android**.
2. Renseigne le `applicationId` **exact** présent dans ton fichier `android/app/build.gradle` (champ `defaultConfig.applicationId`). Si ton projet Flutter n'existe pas encore, crée-le d'abord (`flutter create restaurant_loyalty_app`) pour connaître ce nom de package.
3. Télécharge le fichier `google-services.json` généré.
4. Place-le **exactement** dans `android/app/google-services.json` (pas `android/`, pas `android/app/src/`).

**✅ Résultat attendu** : le fichier existe à `android/app/google-services.json` et son champ `package_name` correspond mot pour mot à ton `applicationId`.

**⚠️ Erreurs à éviter**
- Placer le fichier dans `android/` au lieu de `android/app/` (erreur la plus fréquente).
- Mettre un `applicationId` Firebase différent de celui du projet → erreur silencieuse : pas de crash, mais **aucun token ne sera généré**.

---

### 2.3 — Configurer Gradle pour lire google-services.json

**🔧 Action** — dans `android/settings.gradle.kts` (ou `.gradle`), dans le bloc `plugins`, ajoute :

```kotlin
id("com.google.gms.google-services") version "4.4.2" apply false
```

Puis dans `android/app/build.gradle.kts`, dans le bloc `plugins` :

```kotlin
id("com.google.gms.google-services")
```

Et vérifie dans `android/app/build.gradle.kts` que :

```kotlin
defaultConfig {
    minSdk = 21   // FCM exige minSdk >= 21
}
```

**✅ Résultat attendu** : `flutter build apk --debug` (ou simplement lancer l'app) compile sans erreur Gradle liée à `google-services`.

**⚠️ Erreurs à éviter**
- Oublier `apply false` sur la première ligne → erreur de build "plugin already on classpath".
- `minSdk` trop bas (ex: 19) → build qui échoue avec un message explicite, ne le néglige pas.

---

### 2.4 — Ajouter l'app iOS à Firebase

**🔧 Action**
1. Firebase Console → **Ajouter une application → iOS**, renseigne le Bundle ID exact (visible dans Xcode → Runner → General → Bundle Identifier).
2. Télécharge `GoogleService-Info.plist`.
3. Ouvre `ios/Runner.xcworkspace` dans Xcode, fais un clic droit sur le dossier `Runner` → **Add Files to "Runner"**, sélectionne le fichier. **Coche bien "Copy items if needed"** et vérifie que la cible `Runner` est cochée.

**✅ Résultat attendu** : dans Xcode, le fichier `GoogleService-Info.plist` apparaît dans l'arborescence du projet (pas juste dans le Finder).

**⚠️ Erreur à éviter** : glisser le fichier seulement dans le Finder/VSCode sans passer par Xcode → le fichier existe sur le disque mais n'est pas inclus dans le bundle compilé, donc invisible pour l'app au runtime.

---

### 2.5 — Activer les push notifications côté iOS (obligatoire, souvent oublié)

**🔧 Action**
1. Dans Xcode → Runner → **Signing & Capabilities** → `+ Capability` → ajoute **Push Notifications**.
2. Ajoute aussi **Background Modes** → coche **Remote notifications**.
3. Crée une clé APNs : Apple Developer Portal → **Certificates, Identifiers & Profiles → Keys** → nouvelle clé avec "Apple Push Notifications service (APNs)" activé. Télécharge le fichier `.p8` (téléchargeable **une seule fois**, garde-le).
4. Dans Firebase Console → Paramètres du projet → **Cloud Messaging** → section **Configuration de l'app Apple** → upload ce fichier `.p8` + le Key ID + le Team ID.

**✅ Résultat attendu** : dans Firebase Console, la configuration APNs apparaît en vert/validée pour ton app iOS.

**⚠️ Erreur à éviter** : tester sur le simulateur iOS sans configuration APNs réelle — sur certaines versions ça fonctionne en partie, mais le comportement diffère d'un vrai device. **Pour FCM, teste toujours sur un vrai iPhone dès que possible.**

---

### 2.6 — Installer les dépendances Flutter

**🔧 Action**
```bash
flutter pub add firebase_core firebase_messaging
flutter pub get
```

Dans `lib/main.dart`, **avant** `runApp()` :

```dart
void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await Firebase.initializeApp();
  runApp(const MyApp());
}
```

**✅ Résultat attendu** : l'app se lance sans l'erreur `[core/no-app] No Firebase App '[DEFAULT]' has been created`.

**⚠️ Erreurs à éviter**
- Oublier `WidgetsFlutterBinding.ensureInitialized()` → crash immédiat.
- Oublier le `await` devant `Firebase.initializeApp()` → l'app démarre avant que Firebase soit prêt, et tout appel FCM plus loin échoue de façon imprévisible (bug difficile à reproduire).

---

### 2.7 — Préparer Laravel pour parler à FCM

**🔧 Action**
```bash
composer require google/apiclient
mkdir -p storage/app/firebase
```

Place ton fichier de compte de service (téléchargeable dans Firebase Console → Paramètres du projet → **Comptes de service** → *Générer une nouvelle clé privée*) dans `storage/app/firebase/service-account.json`.

Dans `.gitignore`, ajoute :
```
storage/app/firebase/service-account.json
```

Dans `.env` :
```
FIREBASE_PROJECT_ID=restaurant-loyalty-app-a1b2c
```

**✅ Résultat attendu** :
```bash
php artisan tinker
```
```php
>>> file_exists(storage_path('app/firebase/service-account.json'))
=> true
```

**⚠️ Erreurs à éviter**
- Committer le fichier `service-account.json` sur Git, même dans un repo privé — c'est une clé d'accès complète à ton projet Firebase.
- Confondre ce fichier avec une "clé serveur" legacy — ce n'est pas le même mécanisme, et l'ancienne clé serveur ne fonctionne plus avec l'API v1.

---

### ✅ Checkpoint de fin de Module 2

Avant de continuer, vérifie que les 3 conditions suivantes sont vraies en même temps :
1. L'app Flutter se lance sur Android **et** sur iOS sans erreur Firebase.
2. `storage/app/firebase/service-account.json` existe côté Laravel et n'est pas tracké par Git.
3. Tu connais ton `FIREBASE_PROJECT_ID` par cœur.

Si l'un des trois manque, ne passe pas au Module 3.

---

## MODULE 3 — Device Token (implémentation directe)

### 3.1 — Créer le service Flutter qui gère le token

**🔧 Action** — crée le fichier `lib/services/fcm_service.dart` :

```dart
import 'package:firebase_messaging/firebase_messaging.dart';

class FcmService {
  final FirebaseMessaging _messaging = FirebaseMessaging.instance;

  Future<void> initAfterLogin({required Future<void> Function(String) onTokenReady}) async {
    // 1. Demander la permission (obligatoire sur iOS, et sur Android 13+)
    final settings = await _messaging.requestPermission(
      alert: true,
      badge: true,
      sound: true,
    );

    if (settings.authorizationStatus == AuthorizationStatus.denied) {
      // L'utilisateur a refusé : il ne recevra rien. On ne va pas plus loin.
      return;
    }

    // 2. Récupérer le token actuel
    final token = await _messaging.getToken();
    if (token != null) {
      await onTokenReady(token);
    }

    // 3. Écouter le renouvellement du token (réinstall, changement de device, etc.)
    _messaging.onTokenRefresh.listen((newToken) async {
      await onTokenReady(newToken);
    });
  }
}
```

### 3.2 — Brancher ce service au bon moment

**🔧 Action** — appelle-le **juste après une connexion réussie** (pas dans `main()`, car tu as besoin de l'`user_id` pour l'envoyer au backend) :

```dart
// Dans ta logique de login, juste après authentification réussie
final fcmService = FcmService();
await fcmService.initAfterLogin(onTokenReady: (token) async {
  await apiClient.post('/device-tokens', data: {
    'token': token,
    'platform': Platform.isAndroid ? 'android' : 'ios',
  });
});
```

Appelle-le **aussi** au démarrage de l'app si une session existante est déjà valide (auto-login), pour rafraîchir le token si l'app a été réinstallée.

**✅ Résultat attendu** : juste après ton login dans l'app, tu vois dans les logs Flutter le token (une longue chaîne), et côté Laravel une requête POST arrive sur `/device-tokens`.

**⚠️ Erreurs à éviter**
- Appeler `getToken()` **avant** `requestPermission()` sur iOS → retourne `null` silencieusement, pas d'erreur visible.
- Appeler ce service avant que l'utilisateur soit connecté → tu as un token mais aucun `user_id` pour le rattacher.
- Oublier `onTokenRefresh` → après une réinstallation de l'app, le nouveau token n'est jamais transmis au backend, et le client cesse de recevoir des notifications sans que tu comprennes pourquoi.

---

### 3.3 — Créer la réception côté Laravel

**🔧 Action**
```bash
php artisan make:migration create_device_tokens_table
php artisan make:model DeviceToken
```

Dans la migration :

```php
Schema::create('device_tokens', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('token')->unique();
    $table->enum('platform', ['android', 'ios'])->nullable();
    $table->timestamp('last_used_at')->nullable();
    $table->timestamps();
});
```

```bash
php artisan migrate
```

Dans `app/Models/DeviceToken.php` :
```php
protected $fillable = ['user_id', 'token', 'platform', 'last_used_at'];

public function user()
{
    return $this->belongsTo(User::class);
}
```

Dans `routes/api.php` :
```php
Route::middleware('auth:sanctum')->post('/device-tokens', function (Request $request) {
    $request->validate(['token' => 'required|string']);

    // Important : un token ne peut appartenir qu'à un seul user à la fois.
    // S'il existait déjà rattaché à un autre user (device revendu/partagé), on le détache.
    DeviceToken::where('token', $request->token)
        ->where('user_id', '!=', $request->user()->id)
        ->delete();

    $request->user()->deviceTokens()->updateOrCreate(
        ['token' => $request->token],
        ['platform' => $request->platform, 'last_used_at' => now()]
    );

    return response()->noContent();
});
```

Ajoute la relation dans `User.php` :
```php
public function deviceTokens()
{
    return $this->hasMany(DeviceToken::class);
}
```

**✅ Résultat attendu** : après un login réel dans l'app (sur un vrai device ou émulateur avec Play Services), une ligne apparaît dans la table `device_tokens` avec le bon `user_id`.

**⚠️ Erreurs à éviter**
- Utiliser `create()` au lieu de `updateOrCreate()` → à chaque relance d'app tu crées un doublon et violes la contrainte `unique()`.
- Oublier la suppression du token chez l'ancien propriétaire → un device revendu pourrait recevoir les notifications du nouvel utilisateur ET de l'ancien.

---

### ✅ Checkpoint de fin de Module 3

Connecte-toi dans l'app sur un vrai device. Va voir directement dans ta base de données (Tinker ou un client SQL) : la ligne `device_tokens` doit exister, avec le bon `user_id`, et `platform` correctement rempli. Si ce n'est pas le cas, ne passe pas au Module 4 — débogue ici.

---

## MODULE 4 — Envoyer ta première notification (de bout en bout)

### 4.1 — Créer le service d'envoi Laravel

**🔧 Action** — crée `app/Services/Fcm/FcmService.php` :

```php
namespace App\Services\Fcm;

use Google\Client as GoogleClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService
{
    public function getAccessToken(): string
    {
        // On met le token en cache pour ne pas en régénérer un à chaque envoi
        // (il est valide ~1h, FCM/Google rate-limite la génération de tokens)
        return Cache::remember('fcm_access_token', 3500, function () {
            $client = new GoogleClient();
            $client->setAuthConfig(storage_path('app/firebase/service-account.json'));
            $client->addScope('https://www.googleapis.com/auth/firebase.messaging');

            $token = $client->fetchAccessTokenWithAssertion();
            return $token['access_token'];
        });
    }

    public function sendToToken(string $deviceToken, array $notification, array $data = []): bool
    {
        $projectId = config('services.firebase.project_id');

        $response = Http::withToken($this->getAccessToken())
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => array_filter([
                    'token' => $deviceToken,
                    'notification' => $notification,
                    'data' => $data ?: null,
                ]),
            ]);

        if ($response->successful()) {
            return true;
        }

        // Token mort : on le supprime pour ne plus jamais réessayer
        if ($response->status() === 404 || str_contains($response->body(), 'UNREGISTERED')) {
            \App\Models\DeviceToken::where('token', $deviceToken)->delete();
        }

        Log::warning('FCM send failed', ['status' => $response->status(), 'body' => $response->body()]);
        return false;
    }
}
```

Ajoute dans `config/services.php` :
```php
'firebase' => [
    'project_id' => env('FIREBASE_PROJECT_ID'),
],
```

### 4.2 — Tester l'envoi immédiatement

**🔧 Action** — pas besoin d'UI pour tester, utilise Tinker directement :

```bash
php artisan tinker
```
```php
>>> $token = \App\Models\DeviceToken::first()->token;
>>> app(\App\Services\Fcm\FcmService::class)->sendToToken(
        $token,
        ['title' => 'Happy Hour Tonight 🍹', 'body' => '-20% sur les cocktails ce soir']
    );
```

**✅ Résultat attendu** : `true` retourné dans Tinker, et la notification apparaît sur ton device **si l'app est en arrière-plan ou fermée** (en foreground, rien ne s'affichera — c'est normal, voir Module 5).

**⚠️ Erreurs à éviter**
- Tester avec l'app ouverte au premier plan en pensant qu'il y a un bug parce que "rien ne s'affiche" → comportement normal, pas une erreur (Module 5 explique pourquoi et comment l'afficher quand même).
- Une erreur `403 PERMISSION_DENIED` signifie presque toujours un scope OAuth manquant ou une API Cloud Messaging non activée sur le projet Google Cloud associé — va vérifier dans Google Cloud Console → API activées.
- Une erreur `404` avec `UNREGISTERED` = token invalide (app désinstallée) — c'est géré automatiquement par le code ci-dessus, ne t'inquiète pas si ça arrive en test sur un vieux token.

---

### ✅ Checkpoint de fin de Module 4

Tu dois avoir reçu, au moins une fois, une vraie notification déclenchée depuis Laravel sur ton téléphone, sans passer par la Firebase Console. Si tu n'y es pas encore arrivé, ne continue pas.

---

## MODULE 5 — Gérer les 3 états de l'app (foreground / background / terminated)

### 5.1 — Afficher une notification quand l'app est ouverte (foreground)

**🔧 Action**
```bash
flutter pub add flutter_local_notifications
```

Dans `lib/services/fcm_service.dart`, ajoute :

```dart
import 'package:flutter_local_notifications/flutter_local_notifications.dart';

final _localNotifications = FlutterLocalNotificationsPlugin();

Future<void> initLocalNotifications() async {
  const androidSettings = AndroidInitializationSettings('@mipmap/ic_launcher');
  await _localNotifications.initialize(
    const InitializationSettings(android: androidSettings),
  );
}

void listenForegroundMessages() {
  FirebaseMessaging.onMessage.listen((RemoteMessage message) {
    final notification = message.notification;
    if (notification != null) {
      _localNotifications.show(
        notification.hashCode,
        notification.title,
        notification.body,
        const NotificationDetails(
          android: AndroidNotificationDetails('default_channel', 'Notifications'),
        ),
      );
    }
  });
}
```

Appelle `initLocalNotifications()` et `listenForegroundMessages()` une fois, juste après `Firebase.initializeApp()` dans `main.dart`.

**✅ Résultat attendu** : envoie la notification de test du Module 4 **avec l'app ouverte** → une notification apparaît maintenant aussi dans ce cas.

**⚠️ Erreur à éviter** : oublier de créer un canal de notification Android (`'default_channel'`) cohérent entre l'initialisation et l'affichage — sinon la notification est silencieusement ignorée sur Android 8+.

### 5.2 — Gérer le background

**🔧 Action** — dans `main.dart`, **en dehors de toute classe**, tout en haut du fichier :

```dart
@pragma('vm:entry-point')
Future<void> firebaseMessagingBackgroundHandler(RemoteMessage message) async {
  // Doit rester minimal : pas d'accès UI ici.
}

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await Firebase.initializeApp();
  FirebaseMessaging.onBackgroundMessage(firebaseMessagingBackgroundHandler);
  runApp(const MyApp());
}
```

**✅ Résultat attendu** : mets l'app en arrière-plan (pas fermée), envoie une notification → elle apparaît automatiquement dans la barre système, sans code supplémentaire.

**⚠️ Erreur à éviter** : déclarer `firebaseMessagingBackgroundHandler` comme méthode d'une classe ou fonction locale → ignoré silencieusement par le moteur Flutter, qui exige une fonction top-level avec l'annotation `@pragma('vm:entry-point')`.

### 5.3 — Gérer l'app totalement fermée (terminated)

Ce cas est traité avec la navigation au Module 7 (`getInitialMessage`) — les deux sujets sont liés, on les traite ensemble pour éviter de coder la moitié de la logique deux fois.

---

### ✅ Checkpoint de fin de Module 5

Teste la même notification dans les 3 états (app ouverte, réduite, fermée) et confirme que tu vois bien une notification dans les 3 cas, sans aucune confusion sur le pourquoi du comportement.

---

## MODULE 6 — Historique des notifications (la donnée, pas le design)

Le design de l'écran est entièrement à toi. Voici uniquement la partie données, sans laquelle aucun écran ne peut fonctionner.

### 6.1 — Stocker chaque notification reçue côté Laravel (et pas seulement l'envoyer)

**🔧 Action**
```bash
php artisan make:migration create_notification_logs_table
php artisan make:model NotificationLog
```

```php
Schema::create('notification_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained();
    $table->string('type');     // promo, reward, birthday, reminder
    $table->string('title');
    $table->string('body');
    $table->timestamp('sent_at')->nullable();
    $table->timestamp('opened_at')->nullable();
    $table->timestamps();
});
```

Modifie `FcmService::sendToToken()` pour créer une entrée `NotificationLog` à chaque envoi réussi (ajoute un paramètre `$userId` et `$type` à la méthode), avec `sent_at` rempli.

**🔧 Action** — expose un endpoint pour que Flutter récupère cet historique :

```php
Route::middleware('auth:sanctum')->get('/notifications', function (Request $request) {
    return $request->user()->notificationLogs()->latest()->paginate(20);
});
```

**✅ Résultat attendu** : un appel `GET /notifications` authentifié retourne la liste des notifications déjà envoyées à cet utilisateur, triées par date.

**⚠️ Erreur à éviter** : stocker l'historique uniquement côté Flutter en local (ex: `shared_preferences`) — il serait perdu à la désinstallation, et invisible si l'utilisateur change de device. La source de vérité doit être Laravel.

**🔧 Côté Flutter** : appelle cet endpoint, reçois la liste en JSON, et construis l'UI que tu veux avec — c'est entièrement ta partie design à partir d'ici.

---

## MODULE 7 — Navigation au clic (terminated + background)

### 7.1 — Centraliser la logique de navigation

**🔧 Action** — crée une seule fonction réutilisée partout, dans `lib/services/notification_router.dart` :

```dart
import 'package:flutter/material.dart';

void handleNotificationTap(GlobalKey<NavigatorState> navigatorKey, Map<String, dynamic> data) {
  final type = data['type'];
  switch (type) {
    case 'reward':
      navigatorKey.currentState?.pushNamed('/reward', arguments: data['reward_id']);
      break;
    case 'promo':
      navigatorKey.currentState?.pushNamed('/promo', arguments: data['promo_id']);
      break;
    default:
      navigatorKey.currentState?.pushNamed('/notifications');
  }
}
```

### 7.2 — Brancher cette fonction aux deux bons endroits

**🔧 Action** — dans `main.dart` :

```dart
final navigatorKey = GlobalKey<NavigatorState>();

// Cas 1 : app en arrière-plan, l'utilisateur tape sur la notification système
FirebaseMessaging.onMessageOpenedApp.listen((message) {
  handleNotificationTap(navigatorKey, message.data);
});

// Cas 2 : app totalement fermée, ouverte via tap sur la notification
FirebaseMessaging.instance.getInitialMessage().then((message) {
  if (message != null) {
    handleNotificationTap(navigatorKey, message.data);
  }
});
```

N'oublie pas d'assigner `navigatorKey: navigatorKey` sur ton `MaterialApp`.

**🔧 Côté Laravel** : assure-toi que `sendToToken()` envoie toujours un champ `data.type` cohérent avec ces cas (`reward`, `promo`, etc.) — c'est ce switch qui dirige toute la navigation, donc la convention de nommage doit être strictement respectée des deux côtés.

**✅ Résultat attendu** : envoie une notification `type: reward`, ferme complètement l'app, tape sur la notification → l'app s'ouvre directement sur l'écran reward (le design de cet écran reste le tien).

**⚠️ Erreurs à éviter**
- Mettre la logique de navigation dans `onMessage` (foreground) — ce listener se déclenche à la **réception**, pas au **clic**. Il n'y a jamais de clic possible en foreground puisqu'aucune notification système n'apparaît à cet instant (Module 5.1 gère l'affichage, pas le clic).
- Utiliser des `type` différents entre Laravel et Flutter (typo, casse différente) — le switch tombe dans le `default` sans erreur visible, juste un mauvais écran ouvert.

---

## MODULE 8 — Nettoyage et fiabilité des tokens

**🔧 Action** — crée une commande de nettoyage périodique :

```bash
php artisan make:command PruneStaleDeviceTokens
```

```php
public function handle(): void
{
    DeviceToken::where('last_used_at', '<', now()->subDays(60))->delete();
}
```

Dans `routes/console.php` :
```php
Schedule::command('tokens:prune')->weekly();
```

**✅ Résultat attendu** : après une exécution manuelle (`php artisan tokens:prune`), les tokens trop anciens disparaissent de la table.

**⚠️ Erreur à éviter** : ne jamais nettoyer les tokens → tu finis par payer/perdre du temps à envoyer des requêtes vers des milliers de tokens morts, en plus de fausser tes statistiques d'envoi.

---

## MODULE 9 — Notifications Promo (premier vrai cas métier)

**🔧 Action**
```bash
php artisan make:job SendPromoNotification
```

```php
class SendPromoNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private int $userId,
        private string $token,
        private array $notification
    ) {}

    public function handle(FcmService $fcm): void
    {
        $fcm->sendToToken($this->token, $this->notification, ['type' => 'promo']);
    }
}
```

```php
// Déclenchement (ex: depuis un contrôleur de dashboard)
DeviceToken::whereHas('user', fn ($q) => $q->where('is_active', true))
    ->chunk(200, function ($tokens) use ($notification) {
        foreach ($tokens as $deviceToken) {
            SendPromoNotification::dispatch($deviceToken->user_id, $deviceToken->token, $notification);
        }
    });
```

**🔧 Action obligatoire** : lance un worker pour que la queue tourne réellement :
```bash
php artisan queue:work
```

**✅ Résultat attendu** : déclenche l'envoi sur quelques utilisateurs de test → les jobs apparaissent dans `php artisan queue:work` en cours d'exécution, puis les notifications arrivent.

**⚠️ Erreurs à éviter**
- Oublier `php artisan queue:work` (ou ne pas le configurer en supervisor/systemd en production) → les jobs restent en attente indéfiniment, rien ne part jamais, sans aucune erreur visible côté code.
- Envoyer en boucle synchrone sans `Job`/`Queue` sur un volume important → timeout de la requête HTTP du dashboard.

---

## MODULE 10 — Reward Unlocked (notification déclenchée par un événement métier)

**🔧 Action**
```bash
php artisan make:event StampAdded
php artisan make:event RewardUnlocked
php artisan make:listener CheckRewardUnlock --event=StampAdded
php artisan make:listener SendRewardNotification --event=RewardUnlocked
```

Dans `CheckRewardUnlock::handle()` :
```php
public function handle(StampAdded $event): void
{
    if ($event->card->stamps >= $event->card->required_stamps) {
        $event->card->update(['reward_unlocked_at' => now()]);
        event(new RewardUnlocked($event->card));
    }
}
```

Dans `SendRewardNotification` (implémente `ShouldQueue`) :
```php
public function handle(RewardUnlocked $event): void
{
    foreach ($event->card->user->deviceTokens as $deviceToken) {
        SendPromoNotification::dispatch(
            $event->card->user_id,
            $deviceToken->token,
            ['title' => 'Free Dessert Unlocked 🎉', 'body' => 'Ton dessert offert t’attend en restaurant !']
        );
    }
}
```

Déclenche `event(new StampAdded($card))` à l'endroit exact de ton code où un tampon est validé.

**✅ Résultat attendu** : valide manuellement un tampon sur une carte de fidélité à 4/5 → rien ; valide-en un 5ème → la notification reward part automatiquement, sans appel manuel de ta part.

**⚠️ Erreur à éviter** : appeler directement `SendRewardNotification` depuis le contrôleur qui valide le tampon, en sautant les événements — ça marche à court terme, mais ça recolle la logique métier et la logique de notification, et tu ne pourras plus réutiliser `RewardUnlocked` ailleurs (email, log analytics) sans dupliquer du code.

---

## MODULE 11 — Anniversaire (planification calendaire)

**🔧 Action**
```bash
php artisan make:command SendBirthdayNotifications
```

```php
protected $signature = 'notifications:birthdays';

public function handle(): void
{
    User::whereMonth('birthday', now()->month)
        ->whereDay('birthday', now()->day)
        ->each(function (User $user) {
            foreach ($user->deviceTokens as $deviceToken) {
                SendPromoNotification::dispatch($user->id, $deviceToken->token, [
                    'title' => 'Happy Birthday 🎂',
                    'body'  => 'Un dessert offert t’attend cette semaine !',
                ]);
            }
        });
}
```

Dans `routes/console.php` :
```php
Schedule::command('notifications:birthdays')->dailyAt('08:00');
```

**🔧 Action serveur (obligatoire, souvent oubliée)** — sur le serveur de production, configure un vrai cron système :
```
* * * * * cd /chemin/vers/ton/projet && php artisan schedule:run >> /dev/null 2>&1
```

**✅ Résultat attendu** : exécute manuellement `php artisan notifications:birthdays` avec un utilisateur de test dont la date d'anniversaire est aujourd'hui → il reçoit la notification.

**⚠️ Erreur à éviter** : configurer `Schedule::command()` sans jamais configurer le cron système sur le serveur de prod → **aucune tâche planifiée Laravel ne se déclenche jamais**, en local comme en prod, tant que `schedule:run` n'est pas appelé par un vrai cron.

---

## MODULE 12 — Segmentation

### 12.1 — Topics pour les segments larges et stables

**🔧 Action côté Flutter** (à appeler par exemple juste après le login, selon le statut du user reçu du backend) :
```dart
if (user.isVip) {
  await FirebaseMessaging.instance.subscribeToTopic('vip_customers');
}
```

**🔧 Côté Laravel**, l'envoi à tout le topic en une seule requête :
```php
Http::withToken($fcm->getAccessToken())->post($url, [
    'message' => [
        'topic' => 'vip_customers',
        'notification' => ['title' => 'Accès VIP', 'body' => 'Soirée privée ce vendredi'],
    ],
]);
```

### 12.2 — Requête DB pour les segments fins et dynamiques

**🔧 Action** — réutilise exactement le pattern du Module 9, juste avec une condition différente :
```php
User::where('last_visit_at', '<', now()->subDays(30))
    ->whereHas('deviceTokens')
    ->chunk(200, function ($users) {
        // dispatch SendPromoNotification pour chaque token
    });
```

**✅ Résultat attendu** : abonne un utilisateur de test au topic `vip_customers`, envoie au topic → il reçoit la notification ; un autre utilisateur non-abonné ne la reçoit pas.

**⚠️ Erreur à éviter** : utiliser des topics pour des segments qui changent souvent (ex: "actifs dans les 7 derniers jours") — les abonnements topic ne se mettent pas à jour seuls, il faudrait les recalculer et réabonner/désabonner constamment. Pour ce genre de segment, la requête DB (12.2) est la bonne approche.

---

## MODULE 13 — Dashboard restaurant (API uniquement — le design est à toi)

**🔧 Action** — expose uniquement les routes API nécessaires, le restaurant (via ton interface, que tu conçois comme tu veux) les appelle :

```php
Route::middleware(['auth:sanctum', 'can:manage-restaurant'])->group(function () {
    Route::post('/promos', [PromoController::class, 'store']); // déclenche Module 9
    Route::get('/notifications/history', [NotificationLogController::class, 'index']); // Module 6/14
});
```

`PromoController::store()` ne fait rien de plus que valider les données du formulaire puis appeler exactement le code du Module 9 — ne duplique jamais la logique d'envoi dans le contrôleur.

**✅ Résultat attendu** : un appel `POST /promos` avec un titre/texte/segment déclenche bien l'envoi réel, et `GET /notifications/history` retourne l'historique.

**⚠️ Erreur à éviter** : écrire une seconde version de la logique d'envoi directement dans `PromoController` "pour aller plus vite" — tu auras deux endroits à maintenir et un risque de divergence (ex: un endroit gère le nettoyage des tokens morts, l'autre non).

---

## MODULE 14 — Analytics

**🔧 Action** — endpoint pour que Flutter signale qu'une notification a été ouverte :

```php
Route::middleware('auth:sanctum')->post('/notifications/{log}/opened', function (NotificationLog $log) {
    $log->update(['opened_at' => now()]);
    return response()->noContent();
});
```

**🔧 Côté Flutter**, dans `handleNotificationTap` (Module 7), ajoute l'appel API avec l'id du log (transmis dans `data.log_id` lors de l'envoi) :
```dart
await apiClient.post('/notifications/${data['log_id']}/opened');
```

**🔧 Côté Laravel**, dans `FcmService::sendToToken()`, ajoute `'log_id' => (string) $log->id` dans le `data` envoyé à FCM, après avoir créé l'entrée `NotificationLog`.

**✅ Résultat attendu** : `SELECT * FROM notification_logs` montre, pour une notification donnée, `sent_at` rempli au moment de l'envoi puis `opened_at` rempli après le clic réel sur le téléphone.

**⚠️ Erreur à éviter** : calculer un taux d'ouverture sans jamais avoir vérifié manuellement, sur au moins un cas réel, que `opened_at` se remplit correctement — sinon tu bâtis des KPIs sur une donnée qui ne se met jamais à jour.

---

## Checklist finale de validation du projet complet

Coche uniquement ce que tu as **observé**, pas ce que tu penses avoir codé correctement :

- [ ] Login → ligne créée dans `device_tokens` avec le bon `user_id`
- [ ] Notification reçue depuis Laravel, app fermée
- [ ] Notification reçue depuis Laravel, app en arrière-plan
- [ ] Notification affichée depuis Laravel, app ouverte (foreground)
- [ ] Clic sur notification (app fermée) → ouverture directe sur le bon écran
- [ ] Clic sur notification (app en arrière-plan) → ouverture directe sur le bon écran
- [ ] `GET /notifications` retourne l'historique réel d'un utilisateur
- [ ] Validation d'un tampon à 5/5 → notification reward envoyée automatiquement, sans appel manuel
- [ ] Commande anniversaire testée manuellement sur un utilisateur dont la date est aujourd'hui
- [ ] Abonnement à un topic puis envoi à ce topic, reçu uniquement par les abonnés
- [ ] `opened_at` se remplit réellement en base après un clic réel sur le téléphone
- [ ] Tokens invalides supprimés automatiquement après un envoi en échec (`UNREGISTERED`)

Si chaque case est cochée avec une observation réelle (pas une supposition), tu as un système FCM fonctionnel de bout en bout — pas seulement compris en théorie, mais construit et vérifié.
