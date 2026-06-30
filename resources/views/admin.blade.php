<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Programme de Fidélité</title>
    <!-- Tailwind CSS pour un style rapide et propre -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Vite : Importation de app.js pour Laravel Echo / Reverb -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100 text-gray-800 font-sans p-6">
    <div class="max-w-4xl mx-auto">
        <h1 class="text-3xl font-bold mb-6 text-blue-600">Tableau de Bord Administrateur</h1>
        <p class="mb-8 text-gray-600">Ajoutez des points de fidélité aux clients. L'interface se mettra à jour en temps réel via WebSockets !</p>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            @foreach($users as $user)
            <div class="bg-white p-6 rounded-lg shadow flex flex-col justify-between">
                <div>
                    <h2 class="text-xl font-semibold mb-2">{{ $user->name }}</h2>
                    <p class="text-sm text-gray-500 mb-4">{{ $user->email }}</p>
                    
                    <div class="mb-4">
                        <span class="text-gray-700 font-medium">Points actuels : </span>
                        <span id="points-{{ $user->id }}" class="text-2xl font-bold text-green-600">
                            {{ $user->loyalty_points ?? 0 }}
                        </span>
                    </div>
                </div>
                
                <form class="mt-4 flex items-center space-x-2" onsubmit="addPoint(event, {{ $user->id }})">
                    <input type="number" id="points-input-{{ $user->id }}" value="1" min="1" class="w-20 px-3 py-2 border rounded focus:outline-none focus:ring focus:border-blue-300">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded shadow transition-colors w-full flex justify-center items-center">
                        <span id="btn-text-{{ $user->id }}">Ajouter</span>
                    </button>
                </form>
            </div>
            @endforeach
        </div>
    </div>

    <script>
        // Fonction pour envoyer la requête AJAX d'ajout de point
        async function addPoint(event, customerId) {
            event.preventDefault();
            const input = document.getElementById(`points-input-${customerId}`);
            const btnText = document.getElementById(`btn-text-${customerId}`);
            const pointsToAdd = input.value;

            btnText.innerText = '...';

            try {
                const response = await fetch(`/api/customers/${customerId}/add-point`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ points: parseInt(pointsToAdd) })
                });

                if (response.ok) {
                    const data = await response.json();
                    // On pourrait mettre à jour le score ici avec `data.loyalty_points`,
                    // mais on va laisser Laravel Echo (WebSocket) s'en charger pour prouver que le temps réel fonctionne !
                    btnText.innerText = 'Ajouté !';
                } else {
                    btnText.innerText = 'Erreur';
                }
            } catch (error) {
                console.error('Erreur:', error);
                btnText.innerText = 'Erreur';
            }

            setTimeout(() => {
                btnText.innerText = 'Ajouter';
            }, 2000);
        }

        // Configuration de Laravel Echo pour écouter les événements en temps réel
        document.addEventListener('DOMContentLoaded', () => {
            function initEcho() {
                if (window.Echo) {
                    console.log('Echo est prêt, abonnement aux canaux...');
                    
                    const usersIds = @json($users->pluck('id'));
                    
                    usersIds.forEach(id => {
                        window.Echo.channel(`loyalty.${id}`)
                            .listen('LoyaltyPointAdded', (e) => {
                                console.log(`Événement reçu pour l'utilisateur ${id}:`, e);
                                // Mise à jour de l'UI en temps réel
                                const pointsElement = document.getElementById(`points-${id}`);
                                if (pointsElement) {
                                    // Petite animation visuelle
                                    pointsElement.innerText = e.loyalty_points;
                                    pointsElement.classList.add('text-blue-500', 'scale-110');
                                    setTimeout(() => {
                                        pointsElement.classList.remove('text-blue-500', 'scale-110');
                                    }, 500);
                                }
                            });
                    });
                } else {
                    console.log('Attente de Laravel Echo...');
                    setTimeout(initEcho, 500);
                }
            }
            initEcho();
        });
    </script>
    
    <style>
        /* Petite classe pour l'animation scale */
        .scale-110 {
            transform: scale(1.1);
            transition: transform 0.2s ease-in-out;
            display: inline-block;
        }
    </style>
</body>
</html>
