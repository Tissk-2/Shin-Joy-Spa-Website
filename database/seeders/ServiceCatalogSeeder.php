<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;

class ServiceCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $categories = collect([
            ['slug' => 'massage', 'name_id' => 'Pijat', 'name_en' => 'Massage', 'image_path' => '/img/massage.jpg'],
            ['slug' => 'facial', 'name_id' => 'Perawatan Wajah', 'name_en' => 'Facial Care', 'image_path' => '/img/facial.jpg'],
            ['slug' => 'body', 'name_id' => 'Perawatan Tubuh', 'name_en' => 'Body Treatment', 'image_path' => '/img/body.jpg'],
            ['slug' => 'package', 'name_id' => 'Paket Spa', 'name_en' => 'Spa Packages', 'image_path' => '/img/package.jpg'],
        ])->values()->mapWithKeys(function (array $category, int $index): array {
            $model = ServiceCategory::query()->updateOrCreate(
                ['slug' => $category['slug']],
                [...$category, 'is_active' => true, 'display_order' => $index + 1],
            );

            return [$category['slug'] => $model];
        });

        foreach ($this->services() as $index => $service) {
            $category = $categories->get($service['category']);

            Service::query()->updateOrCreate(
                ['slug' => $service['slug']],
                [
                    'category_id' => $category->id,
                    'name_id' => $service['name_id'],
                    'name_en' => $service['name_en'],
                    'description_id' => $service['description_id'],
                    'description_en' => $service['description_en'],
                    'long_description_id' => $service['long_description_id'],
                    'long_description_en' => $service['long_description_en'],
                    'good_for_id' => $service['good_for_id'],
                    'good_for_en' => $service['good_for_en'],
                    'duration_minutes' => $service['duration_minutes'],
                    'price_idr' => $service['price_idr'],
                    'image_path' => $service['image_path'],
                    'gallery_paths' => $service['gallery_paths'],
                    'popularity' => $service['popularity'],
                    'is_active' => true,
                    'display_order' => $index + 1,
                ],
            );
        }
    }

    /** @return list<array<string, mixed>> */
    private function services(): array
    {
        return [
            ['slug' => 'javanese-massage', 'category' => 'massage', 'name_id' => 'Pijat Tradisional Jawa', 'name_en' => 'Traditional Javanese Massage', 'description_id' => 'Tekanan ritmis dengan minyak hangat untuk melemaskan otot tegang.', 'description_en' => 'Rhythmic pressure with warm oil to release tense muscles.', 'long_description_id' => 'Pijat khas Jawa yang memadukan tekanan lembut hingga sedang dengan minyak aromatik hangat. Membantu melancarkan peredaran darah, meredakan pegal, dan mengembalikan rasa ringan pada tubuh.', 'long_description_en' => 'A signature Javanese massage blending gentle-to-medium pressure with warm aromatic oil. It eases circulation, soothes aches, and brings lightness back to the body.', 'good_for_id' => 'Pegal & stres', 'good_for_en' => 'Aches & stress', 'duration_minutes' => 90, 'price_idr' => 185000, 'image_path' => '/img/massage.jpg', 'gallery_paths' => ['massage.jpg', 'oil.jpg', 'stilllife.jpg'], 'popularity' => 98],
            ['slug' => 'aromatherapy-massage', 'category' => 'massage', 'name_id' => 'Pijat Aromaterapi', 'name_en' => 'Aromatherapy Massage', 'description_id' => 'Sentuhan lembut berpadu minyak esensial pilihan yang menenangkan.', 'description_en' => 'Gentle strokes paired with calming, hand-picked essential oils.', 'long_description_id' => 'Perpaduan pijatan lembut dan minyak esensial yang dipilih sesuai suasana hati Anda. Aroma menenangkan membantu pikiran rileks sementara otot perlahan mengendur.', 'long_description_en' => 'A pairing of soft strokes and essential oils chosen to suit your mood. Calming aromas relax the mind while muscles slowly unwind.', 'good_for_id' => 'Relaksasi & tidur', 'good_for_en' => 'Relaxation & sleep', 'duration_minutes' => 60, 'price_idr' => 150000, 'image_path' => '/img/oil.jpg', 'gallery_paths' => ['oil.jpg', 'massage.jpg', 'stilllife.jpg'], 'popularity' => 95],
            ['slug' => 'hot-stone-massage', 'category' => 'massage', 'name_id' => 'Pijat Batu Panas', 'name_en' => 'Hot Stone Massage', 'description_id' => 'Batu vulkanik hangat melepas ketegangan jauh di dalam otot.', 'description_en' => 'Warm volcanic stones melt away deep muscle tension.', 'long_description_id' => 'Batu basal yang dihangatkan diletakkan pada titik-titik tubuh, lalu dipadukan dengan pijatan. Panasnya menembus otot, meredakan kekakuan, dan memberi rasa hangat yang dalam.', 'long_description_en' => 'Heated basalt stones are placed along the body and combined with massage. The warmth sinks into muscles, easing stiffness with a deep, comforting heat.', 'good_for_id' => 'Otot kaku', 'good_for_en' => 'Stiff muscles', 'duration_minutes' => 90, 'price_idr' => 220000, 'image_path' => '/img/body.jpg', 'gallery_paths' => ['body.jpg', 'oil.jpg', 'about.jpg'], 'popularity' => 90],
            ['slug' => 'deep-tissue-massage', 'category' => 'massage', 'name_id' => 'Pijat Otot Dalam', 'name_en' => 'Deep Tissue Massage', 'description_id' => 'Tekanan terfokus untuk simpul otot yang membandel.', 'description_en' => 'Focused pressure for stubborn knots and tension.', 'long_description_id' => 'Teknik tekanan lebih dalam yang menyasar lapisan otot dan jaringan ikat. Ideal bagi Anda yang sering duduk lama atau berolahraga dan merasakan ketegangan menahun.', 'long_description_en' => 'A firmer technique targeting deeper muscle and connective tissue. Ideal if you sit for long hours or train, and carry lingering tension.', 'good_for_id' => 'Nyeri kronis', 'good_for_en' => 'Chronic tension', 'duration_minutes' => 75, 'price_idr' => 200000, 'image_path' => '/img/hands.jpg', 'gallery_paths' => ['hands.jpg', 'massage.jpg', 'oil.jpg'], 'popularity' => 80],
            ['slug' => 'brightening-facial', 'category' => 'facial', 'name_id' => 'Facial Pencerah', 'name_en' => 'Brightening Facial', 'description_id' => 'Membersihkan, mencerahkan, dan menyegarkan kulit wajah.', 'description_en' => 'Cleanses, brightens, and refreshes tired skin.', 'long_description_id' => 'Rangkaian pembersihan mendalam, eksfoliasi lembut, dan masker pencerah untuk kulit yang tampak lebih segar dan merata. Diakhiri pijatan wajah yang menenangkan.', 'long_description_en' => 'A routine of deep cleansing, gentle exfoliation, and a brightening mask for fresher, more even-looking skin, finished with a soothing facial massage.', 'good_for_id' => 'Kulit kusam', 'good_for_en' => 'Dull skin', 'duration_minutes' => 60, 'price_idr' => 175000, 'image_path' => '/img/facial.jpg', 'gallery_paths' => ['facial.jpg', 'facial2.jpg', 'stilllife.jpg'], 'popularity' => 92],
            ['slug' => 'antiaging-facial', 'category' => 'facial', 'name_id' => 'Facial Anti-Penuaan', 'name_en' => 'Anti-Aging Facial', 'description_id' => 'Perawatan menutrisi untuk kulit yang lebih kenyal dan elastis.', 'description_en' => 'A nourishing treatment for firmer, more supple skin.', 'long_description_id' => 'Serum konsentrat dan masker menutrisi bekerja meremajakan kulit, membantu menyamarkan garis halus, dan mengembalikan kekenyalan. Pijat wajah merangsang kekencangan alami.', 'long_description_en' => 'Concentrated serums and a nourishing mask work to renew the skin, soften fine lines, and restore suppleness, with facial massage to encourage natural firmness.', 'good_for_id' => 'Garis halus', 'good_for_en' => 'Fine lines', 'duration_minutes' => 75, 'price_idr' => 240000, 'image_path' => '/img/facial2.jpg', 'gallery_paths' => ['facial2.jpg', 'facial.jpg', 'oil.jpg'], 'popularity' => 84],
            ['slug' => 'acne-facial', 'category' => 'facial', 'name_id' => 'Facial Perawatan Jerawat', 'name_en' => 'Acne Care Facial', 'description_id' => 'Menenangkan, membersihkan pori, dan merawat kulit berjerawat.', 'description_en' => 'Calms, clears pores, and cares for acne-prone skin.', 'long_description_id' => 'Pembersihan menyeluruh dengan produk lembut yang membantu mengangkat kotoran penyumbat pori dan menenangkan kemerahan, tanpa membuat kulit terasa tertarik.', 'long_description_en' => 'A thorough cleanse with gentle products that help lift pore-clogging buildup and calm redness, without leaving skin feeling tight.', 'good_for_id' => 'Kulit berjerawat', 'good_for_en' => 'Acne-prone skin', 'duration_minutes' => 60, 'price_idr' => 165000, 'image_path' => '/img/facial.jpg', 'gallery_paths' => ['facial.jpg', 'facial2.jpg', 'stilllife.jpg'], 'popularity' => 76],
            ['slug' => 'body-scrub', 'category' => 'body', 'name_id' => 'Lulur & Body Scrub', 'name_en' => 'Body Scrub & Glow', 'description_id' => 'Eksfoliasi tradisional untuk kulit yang halus dan bercahaya.', 'description_en' => 'Traditional exfoliation for smooth, glowing skin.', 'long_description_id' => 'Lulur rempah tradisional mengangkat sel kulit mati, lalu dibilas hangat dan dilembapkan. Kulit terasa lebih halus, lembut, dan harum sepanjang hari.', 'long_description_en' => 'A traditional herbal scrub lifts away dead skin, followed by a warm rinse and moisturising. Skin feels smoother, softer, and lightly scented all day.', 'good_for_id' => 'Kulit kasar', 'good_for_en' => 'Rough skin', 'duration_minutes' => 60, 'price_idr' => 160000, 'image_path' => '/img/stilllife.jpg', 'gallery_paths' => ['stilllife.jpg', 'body.jpg', 'oil.jpg'], 'popularity' => 88],
            ['slug' => 'body-wrap', 'category' => 'body', 'name_id' => 'Body Wrap Detoks', 'name_en' => 'Detox Body Wrap', 'description_id' => 'Bungkus tubuh menutrisi untuk kulit yang segar kembali.', 'description_en' => 'A nourishing wrap that leaves skin renewed.', 'long_description_id' => 'Setelah eksfoliasi, tubuh dibalut masker kaya nutrisi yang membantu melembapkan dan menyegarkan kulit. Ditutup dengan pijatan ringan agar Anda benar-benar rileks.', 'long_description_en' => 'After exfoliation, the body is wrapped in a nutrient-rich mask that helps hydrate and refresh the skin, closing with a light massage so you fully relax.', 'good_for_id' => 'Kulit lelah', 'good_for_en' => 'Tired skin', 'duration_minutes' => 75, 'price_idr' => 195000, 'image_path' => '/img/bath.jpg', 'gallery_paths' => ['bath.jpg', 'stilllife.jpg', 'about.jpg'], 'popularity' => 72],
            ['slug' => 'foot-reflexology', 'category' => 'body', 'name_id' => 'Refleksi Kaki', 'name_en' => 'Foot Reflexology', 'description_id' => 'Tekanan pada titik kaki untuk tubuh yang lebih ringan.', 'description_en' => 'Pressure on key foot points for a lighter body.', 'long_description_id' => 'Pijat refleksi pada titik-titik telapak kaki yang terhubung dengan seluruh tubuh. Membantu memperlancar aliran energi, meredakan lelah, dan menenangkan pikiran.', 'long_description_en' => "Reflexology on the foot's pressure points, connected to the whole body. It helps energy flow, eases fatigue, and quiets the mind.", 'good_for_id' => 'Kaki lelah', 'good_for_en' => 'Tired feet', 'duration_minutes' => 45, 'price_idr' => 110000, 'image_path' => '/img/hands.jpg', 'gallery_paths' => ['hands.jpg', 'oil.jpg', 'stilllife.jpg'], 'popularity' => 86],
            ['slug' => 'serenity-package', 'category' => 'package', 'name_id' => 'Paket Serenity', 'name_en' => 'Serenity Spa Package', 'description_id' => 'Pijat, lulur, dan facial dalam satu ritual yang utuh.', 'description_en' => 'Massage, scrub, and facial in one complete ritual.', 'long_description_id' => 'Ritual lengkap selama 2,5 jam: pijat aromaterapi, lulur tubuh, dan facial pencerah. Sebuah hadiah utuh untuk tubuh dan pikiran, ditemani teh hangat di akhir sesi.', 'long_description_en' => 'A full 2.5-hour ritual: aromatherapy massage, body scrub, and a brightening facial. A complete gift for body and mind, closing with a warm cup of tea.', 'good_for_id' => 'Me-time penuh', 'good_for_en' => 'A full me-time', 'duration_minutes' => 150, 'price_idr' => 450000, 'image_path' => '/img/package.jpg', 'gallery_paths' => ['package.jpg', 'facial.jpg', 'stilllife.jpg'], 'popularity' => 99],
            ['slug' => 'couple-package', 'category' => 'package', 'name_id' => 'Paket Spa Berdua', 'name_en' => 'Couple Retreat Package', 'description_id' => 'Ritual spa lengkap untuk dua orang dalam satu ruang privat.', 'description_en' => 'A complete spa ritual for two in a private room.', 'long_description_id' => 'Nikmati rangkaian pijat, lulur, dan perawatan wajah berdua dalam ruang privat. Waktu tenang untuk berbagi rileks, lengkap dengan cokelat hangat dan buah segar.', 'long_description_en' => 'Enjoy a series of massage, scrub, and facial side by side in a private room. Quiet time to unwind together, complete with warm chocolate and fresh fruit.', 'good_for_id' => 'Pasangan', 'good_for_en' => 'Couples', 'duration_minutes' => 180, 'price_idr' => 850000, 'image_path' => '/img/about.jpg', 'gallery_paths' => ['about.jpg', 'package.jpg', 'oil.jpg'], 'popularity' => 85],
        ];
    }
}
