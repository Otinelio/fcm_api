<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class StoreLoyaltyProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Doit rester synchronisée avec `_stampEmojiChoices`/`_stampIconChoices`
     * dans `merchant_step3_screen.dart` (Flutter) — ce sont les seuls choix
     * proposés à l'utilisateur, toute valeur hors liste est un payload forgé.
     */
    public function rules(): array
    {
        return [
            // Pas de mode "points" indépendant : les points ne sont qu'une
            // unité de progression interne au mode "spend" (Achats).
            'mode'                    => ['required', 'string', 'in:stamps,spend,cashback'],
            // Champ vestigial : plus utilisé (remplacé par `tiers.*.goal`),
            // laissé `nullable` uniquement pour que d'anciennes données
            // stockées ou des tests qui y font encore référence ne fassent
            // pas échouer la validation. `tiers[]` est désormais *requis*
            // pour stamps/spend (voir plus bas) — tout client qui envoie
            // encore seulement `goal` sans `tiers` reçoit un 422, c'est un
            // changement cassant pour les clients tiers pas encore à jour.
            'goal'                    => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'cashback_percentage'     => ['required_if:mode,cashback', 'numeric', 'min:0.1', 'max:100'],
            // Seuil de solde cashback (FCFA) à atteindre avant que le client
            // puisse l'utiliser — remplace l'ancien plafond en % du ticket.
            'cashback_redeem_threshold_fcfa' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            // Base de calcul des paliers cashback : `cumulative` (défaut,
            // cashback cumulé à vie, ne redescend jamais) ou `balance` (solde
            // disponible en direct, peut redescendre si le client l'utilise).
            // Un seul réglage pour tout le programme, jamais par palier — les
            // paliers partagent une roadmap unique (niveau/pourcentage), qui
            // suppose une seule métrique croissante et triable.
            'cashback_tier_basis'    => ['nullable', 'string', 'in:cumulative,balance'],
            // Expiration du solde cashback (jours sans crédit) — optionnelle.
            'cashback_expiry_days'    => ['nullable', 'integer', 'min:1', 'max:3650'],
            // Récompense anniversaire — indépendante du mode (stamps/spend/
            // cashback), un seul réglage par commerce. Voir
            // `SendBirthdayNotifications`.
            'birthday_reward_enabled'        => ['sometimes', 'boolean'],
            'birthday_reward_title'          => ['nullable', 'required_if:birthday_reward_enabled,true', 'string', 'max:255'],
            'birthday_reward_description'    => ['nullable', 'string', 'max:500'],
            // Durée de validité (jours) depuis l'anniversaire — `null` = pas d'expiration.
            'birthday_reward_validity_days'  => ['nullable', 'integer', 'min:1', 'max:365'],
            // Taux de conversion mode "Achat" (FCFA pour 1 point) — 100 par
            // défaut côté Flutter, réglable par restaurant.
            'fcfa_per_point'          => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'reward_description'      => ['nullable', 'string', 'max:255'],
            // Paliers unifiés (niveau + récompense fusionnés) — remplace les
            // anciens `rewards[]`/`levels[]` distincts. Requis pour
            // stamps/spend (le cycle a besoin d'au moins un objectif),
            // optionnel pour cashback (pas de cycle objectif/récompense).
            'tiers'                   => [
                'required_unless:mode,cashback',
                'array',
                'min:1',
                function ($attribute, $value, $fail) {
                    if (is_array($value)) {
                        $lastGoal = 0;
                        foreach ($value as $index => $tier) {
                            $goal = isset($tier['goal']) ? (int) $tier['goal'] : 0;
                            if ($goal <= $lastGoal) {
                                $fail('Le palier #' . ($index + 1) . " ($goal) doit être strictement supérieur au palier précédent ($lastGoal).");

                                return;
                            }
                            $lastGoal = $goal;
                        }
                    }
                },
            ],
            'tiers.*.goal'                => ['required', 'integer', 'min:1', 'max:1000000'],
            'tiers.*.level_name'          => ['nullable', 'string', 'max:100'],
            // Icône choisie par le marchand pour les paliers en position 6+
            // (au-delà de la position 5, nom et icône sont libres — voir
            // `LoyaltyTierService`). Palette (`TierIconPalette`) définie côté
            // Flutter uniquement : on reste permissif ici, comme `level_name`.
            'tiers.*.icon_key'            => ['nullable', 'string', 'max:100'],
            // Optionnelle pour cashback (un palier peut ne servir qu'à
            // attribuer un niveau) ; reste obligatoire pour stamps/spend.
            'tiers.*.reward_description'  => ['required_unless:mode,cashback', 'nullable', 'string', 'max:255'],
            // Récompense "surprise" propre à ce palier — `false` = contenu
            // masqué côté client jusqu'au déblocage réel. Défaut `true`.
            'tiers.*.reveal_reward'       => ['sometimes', 'boolean'],
            // Durée de validité propre à ce palier — `null` = utilise
            // `reward_validity_days` (valeur par défaut du programme).
            'tiers.*.validity_days'       => ['nullable', 'integer', 'min:1', 'max:3650'],
            // Comportement après le dernier palier : `true` = nouveau cycle
            // automatique (progression repart à 0, récompense déjà
            // débloquée conservée) ; `false` = carte terminée définitivement.
            'loops'                   => ['sometimes', 'boolean'],
            // Durée de validité d'une récompense débloquée, en jours.
            // `null`/absent = pas d'expiration.
            'reward_validity_days'    => ['nullable', 'integer', 'min:1', 'max:3650'],
            'show_review_button'      => ['sometimes', 'boolean'],
            'google_review_url'       => ['nullable', 'string', 'url', 'max:255', 'required_if:show_review_button,true'],
            'color_primary'           => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'color_secondary'         => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'stamp_design_type'       => ['required', 'string', 'in:check,icon,emoji'],
            'stamp_emoji'             => ['nullable', 'string', 'in:✨,🎁,⭐,❤️,☕,🍰,🔥,💎,🏆,👑,🌟,💫,🎉,🍕,🍔,🧋', 'required_if:stamp_design_type,emoji'],
            'stamp_icon'              => ['nullable', 'string', 'in:check_rounded,star_rounded,favorite_rounded,local_cafe_rounded,card_giftcard_rounded,auto_awesome_rounded,emoji_emotions_rounded,diamond_rounded', 'required_if:stamp_design_type,icon'],
            'card_decoration_pattern' => ['nullable', 'string', 'in:none,lines,waves,dots'],
            'card_gradient_type'      => ['nullable', 'string', 'in:linear,radial'],
            'logo_url'                => ['nullable', 'string', 'url', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'mode.required'                         => 'Le mode de récompense est obligatoire.',
            'birthday_reward_title.required_if'     => 'Le titre de la récompense anniversaire est obligatoire quand elle est activée.',
            'mode.in'                               => 'Mode de récompense invalide.',
            'goal.max'                              => 'L\'objectif est trop élevé.',
            'cashback_percentage.required_if'       => 'Le pourcentage de cashback est obligatoire.',
            'tiers.required_unless'                 => 'Au moins un palier est obligatoire.',
            'tiers.*.goal.required'                 => 'Le palier de chaque récompense est obligatoire.',
            'tiers.*.reward_description.required'   => 'La description de chaque récompense est obligatoire.',
            'google_review_url.required_if'         => 'Veuillez renseigner le lien d\'avis.',
            'google_review_url.url'                 => 'Lien d\'avis invalide.',
            'color_primary.regex'                   => 'Couleur principale invalide.',
            'color_secondary.regex'                 => 'Couleur secondaire invalide.',
            'stamp_emoji.required_if'               => 'Veuillez choisir un emoji.',
            'stamp_emoji.in'                        => 'Emoji invalide.',
            'stamp_icon.required_if'                => 'Veuillez choisir une icône.',
            'stamp_icon.in'                         => 'Icône invalide.',
        ];
    }
}
