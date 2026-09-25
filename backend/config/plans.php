<?php

/*
 * Formules qu'un hôte peut partager, par service.
 * - max : places partageables (l'hôte garde toujours la sienne)
 * - devices : appareils possibles ; l'hôte coche ceux qu'il autorise
 * - mode : identifiants partagés ou invitation famille
 * - reco : fourchette de prix conseillée par place (FCFA)
 */

$all = ['phone', 'tablet', 'computer', 'tv'];

return [
    'netflix' => [
        'standard' => ['label' => 'Standard · 2 écrans', 'max' => 1, 'quality' => 'HD', 'devices' => $all, 'mode' => 'credentials', 'own' => 5500, 'reco' => [2000, 2800]],
        'premium' => ['label' => 'Premium · 4 écrans', 'max' => 3, 'quality' => '4K', 'devices' => $all, 'mode' => 'credentials', 'own' => 8000, 'reco' => [2000, 2700]],
    ],
    'spotify' => [
        'famille' => ['label' => 'Famille · 6 comptes', 'max' => 5, 'quality' => null, 'devices' => $all, 'mode' => 'family', 'own' => 5500, 'reco' => [1200, 1600]],
    ],
    'apple-music' => [
        'famille' => ['label' => 'Famille · 6 comptes', 'max' => 5, 'quality' => null, 'devices' => $all, 'mode' => 'family', 'own' => 5500, 'reco' => [1200, 1600]],
    ],
    'spotify-duo' => [
        'duo' => ['label' => 'Duo · 2 comptes', 'max' => 1, 'quality' => null, 'devices' => $all, 'mode' => 'family', 'own' => 4400, 'reco' => [2000, 2400]],
    ],
    'youtube' => [
        'famille' => ['label' => 'Famille · 6 comptes', 'max' => 5, 'quality' => null, 'devices' => $all, 'mode' => 'family', 'own' => 5200, 'reco' => [1400, 1900]],
    ],
    'disney' => [
        'premium' => ['label' => 'Premium · 4 écrans', 'max' => 3, 'quality' => '4K', 'devices' => $all, 'mode' => 'credentials', 'own' => 6000, 'reco' => [1800, 2300]],
    ],
    'prime' => [
        'standard' => ['label' => 'Prime · 3 écrans', 'max' => 2, 'quality' => 'HD', 'devices' => $all, 'mode' => 'credentials', 'own' => 2700, 'reco' => [1000, 1400]],
    ],
    'canal' => [
        'evasion' => ['label' => 'Évasion · 3 écrans', 'max' => 2, 'quality' => 'HD', 'devices' => $all, 'mode' => 'credentials', 'own' => 7500, 'reco' => [2600, 3300]],
    ],
    'canal-sport' => [
        'sport' => ['label' => 'Sport · 3 écrans', 'max' => 2, 'quality' => 'HD', 'devices' => $all, 'mode' => 'credentials', 'own' => 12000, 'reco' => [3500, 4500]],
    ],
    'chatgpt' => [
        'team' => ['label' => 'Team · 2 sièges', 'max' => 1, 'quality' => null, 'devices' => ['phone', 'computer'], 'mode' => 'credentials', 'own' => 13000, 'reco' => [4500, 5500]],
    ],
];
