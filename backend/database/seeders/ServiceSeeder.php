<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    /** Catalogue identique à celui de la PWA (src/lib/data.ts). Les places libres viennent des offres (DemoSeeder). */
    public function run(): void
    {
        $services = [
            ['netflix', 'N', 'Netflix', '#E50914', '#FFFFFF', 'streaming', 'Standard ou Premium 4K', 'Ton propre profil, sans pub. Choisis l’offre selon tes écrans : téléphone, ordinateur ou TV.', 2500, 8000, 5, 1, 15, false],
            ['spotify', 'S', 'Spotify Famille', '#1DB954', '#0B0B0B', 'music', 'Sans pub · hors ligne', 'Ton compte Spotify Premium perso dans un groupe famille. Sans pub, écoute hors ligne.', 1500, 5500, 6, 2, 30, true],
            ['deezer', 'DZ', 'Deezer Famille', '#A238FF', '#FFFFFF', 'music', 'Sans pub · hors ligne', 'Ton compte Deezer Premium perso dans un groupe famille. Sans pub, écoute hors ligne.', 900, 4500, 6, 0, 30, false],
            ['apple-music', 'AM', 'Apple Music', '#FA243C', '#FFFFFF', 'music', 'Famille · sans pub', 'Ton compte Apple Music perso dans un groupe famille : tout le catalogue, sans pub, écoute hors ligne.', 1500, 5500, 6, 3, 30, true],
            ['youtube', 'Y', 'YouTube Premium', '#FF0033', '#FFFFFF', 'streaming', 'Sans pub · Music inclus', 'YouTube sans pub, lecture en arrière-plan et YouTube Music inclus.', 1800, 5200, 6, 0, 30, false],
            ['canal', 'C+', 'Canal+ Évasion', '#16130F', '#FFFFFF', 'streaming', 'Chaînes + replay', 'Les chaînes Canal+ Évasion en direct et en replay sur myCANAL.', 3000, 7500, 3, 1, 20, false],
            ['prime', 'P', 'Prime Video', '#1A98FF', '#0B0B0B', 'streaming', 'Films · séries', 'Films, séries et originaux Amazon. Ton profil perso.', 1200, 2700, 5, 3, 15, true],
            ['chatgpt', 'AI', 'ChatGPT Plus', '#10A37F', '#FFFFFF', 'ia', 'Modèles avancés · images', 'Accès prioritaire aux derniers modèles, génération d’images et analyse de fichiers.', 5000, 13000, 2, 1, 10, true],
            ['disney', 'D+', 'Disney+', '#0E2A6B', '#FFFFFF', 'streaming', 'Marvel · Pixar · Star Wars', 'Disney, Pixar, Marvel, Star Wars et National Geographic en 4K.', 2000, 6000, 4, 3, 15, true],
            ['spotify-duo', 'S', 'Spotify Duo', '#1DB954', '#0B0B0B', 'music', '2 comptes Premium', 'Deux comptes Premium sous un même toit. Mix Duo inclus.', 2200, 4400, 2, 0, 30, false],
            ['canal-sport', 'C+', 'Canal+ Sport', '#16130F', '#FFFFFF', 'sport', 'Foot · Ligue 1 · CAN', 'Tout le sport Canal+ : championnats européens, CAN et Ligue 1.', 4000, 12000, 3, 1, 20, false],
            ['crunchyroll', 'CR', 'Crunchyroll', '#F47521', '#FFFFFF', 'streaming', 'Animés · simulcast', 'Tous les animés sans pub, en simulcast avec le Japon, et hors ligne sur mobile.', 1000, 4000, 6, 0, 15, false],
        ];

        foreach ($services as $i => [$slug, $mono, $name, $color, $fg, $cat, $meta, $desc, $price, $full, $seats, $free, $activation, $popular]) {
            Service::updateOrCreate(['slug' => $slug], [
                'mono' => $mono, 'name' => $name, 'color' => $color, 'fg' => $fg, 'category' => $cat,
                'meta' => $meta, 'description' => $desc, 'price' => $price, 'full_price' => $full,
                'seats' => $seats, 'activation_minutes' => $activation,
                'position' => $i, 'is_popular' => $popular, 'is_active' => true,
            ]);
        }
    }
}
