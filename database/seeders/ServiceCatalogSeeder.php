<?php

namespace Database\Seeders;

use App\Enums\TreatmentMode;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceExtension;
use App\Models\ServiceVariant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ServiceCatalogSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $category = ServiceCategory::query()->updateOrCreate(
                ['slug' => 'package'],
                [
                    'name_id' => 'Paket Spa',
                    'name_en' => 'Spa Packages',
                    'image_path' => '/img/package.jpg',
                    'is_active' => true,
                    'display_order' => 1,
                ],
            );

            ServiceCategory::query()
                ->whereIn('slug', ['massage', 'facial', 'body'])
                ->update(['is_active' => false]);

            $services = [];
            foreach ($this->packages() as $slug => $package) {
                $services[$slug] = Service::query()->updateOrCreate(
                    ['slug' => $slug],
                    [
                        'category_id' => $category->id,
                        ...$package,
                        'long_description_id' => $package['description_id'],
                        'long_description_en' => $package['description_en'],
                        'popularity' => $slug === 'nezuko' ? 100 : 99,
                        'is_active' => true,
                    ],
                );
            }

            Service::query()
                ->whereIn('slug', $this->placeholderSlugs())
                ->update(['is_active' => false]);

            $this->seedVariants($services);
            $this->seedExtensions($services);
        });
    }

    /** @return array<string, array<string, mixed>> */
    private function packages(): array
    {
        return [
            'nezuko' => [
                'name_id' => 'Nezuko', 'name_en' => 'Nezuko', 'is_vip' => false,
                'description_id' => 'Pijatan lembut dengan perpaduan pijat tradisional dan pijat Thailand, menggunakan minyak atau tanpa minyak, yang menghadirkan sensasi relaksasi.',
                'description_en' => 'A gentle treatment combining traditional and Thai massage techniques, with or without oil, designed to create a deeply relaxing experience.',
                'good_for_id' => 'Membantu memperlancar peredaran darah dan meningkatkan kualitas tidur.',
                'good_for_en' => 'Helps support healthy circulation and improve sleep quality.',
                'image_path' => '/img/massage.jpg',
                'gallery_paths' => ['massage.jpg', 'oil.jpg', 'about.jpg'],
                'duration_minutes' => null, 'price_idr' => null, 'display_order' => 1,
            ],
            'nezuko-vip' => [
                'name_id' => 'Nezuko VIP', 'name_en' => 'Nezuko VIP', 'is_vip' => true,
                'description_id' => 'Perpaduan pijat tradisional dan pijat Thailand, menggunakan minyak atau tanpa minyak, yang disesuaikan dengan kebutuhan dan keluhan klien untuk menghadirkan sensasi relaksasi.',
                'description_en' => "A tailored blend of traditional and Thai massage, with or without oil, adjusted to the client's needs and preferences for a deeply relaxing experience.",
                'good_for_id' => 'Membantu memperlancar peredaran darah dan meningkatkan kualitas tidur.',
                'good_for_en' => 'Helps support healthy circulation and improve sleep quality.',
                'image_path' => '/img/package.jpg',
                'gallery_paths' => ['package.jpg', 'hands.jpg', 'oil.jpg'],
                'duration_minutes' => null, 'price_idr' => null, 'display_order' => 2,
            ],
        ];
    }

    /** @param array<string, Service> $services */
    private function seedVariants(array $services): void
    {
        $rows = [
            ['service' => 'nezuko', 'code' => 'nezuko-regular-60', 'mode' => TreatmentMode::Regular, 'duration' => 60, 'price' => 199000],
            ['service' => 'nezuko', 'code' => 'nezuko-regular-90', 'mode' => TreatmentMode::Regular, 'duration' => 90, 'price' => 265000],
            ['service' => 'nezuko', 'code' => 'nezuko-regular-120', 'mode' => TreatmentMode::Regular, 'duration' => 120, 'price' => 315000],
            ['service' => 'nezuko', 'code' => 'nezuko-double-60', 'mode' => TreatmentMode::DoubleTherapist, 'duration' => 60, 'price' => 250000],
            ['service' => 'nezuko-vip', 'code' => 'nezuko-vip-regular-60', 'mode' => TreatmentMode::Regular, 'duration' => 60, 'price' => 299000],
            ['service' => 'nezuko-vip', 'code' => 'nezuko-vip-regular-90', 'mode' => TreatmentMode::Regular, 'duration' => 90, 'price' => 349000],
            ['service' => 'nezuko-vip', 'code' => 'nezuko-vip-regular-120', 'mode' => TreatmentMode::Regular, 'duration' => 120, 'price' => 499000],
            ['service' => 'nezuko-vip', 'code' => 'nezuko-vip-double-60', 'mode' => TreatmentMode::DoubleTherapist, 'duration' => 60, 'price' => 350000],
        ];

        $this->removeConflictingVariantChoices($services, $rows);

        foreach ($rows as $index => $row) {
            ServiceVariant::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'service_id' => $services[$row['service']]->id,
                    'treatment_mode' => $row['mode'],
                    'duration_minutes' => $row['duration'],
                    'price_idr' => $row['price'],
                    'is_active' => true,
                    'display_order' => $index + 1,
                ],
            );
        }

        ServiceVariant::query()
            ->whereIn('service_id', collect($services)->pluck('id'))
            ->whereNotIn('code', collect($rows)->pluck('code'))
            ->update(['is_active' => false]);
    }

    /** @param array<string, Service> $services */
    private function seedExtensions(array $services): void
    {
        $rows = [
            ['service' => 'nezuko', 'code' => 'nezuko-regular-extra-60', 'mode' => TreatmentMode::Regular, 'duration' => 60, 'price' => 75000],
            ['service' => 'nezuko', 'code' => 'nezuko-double-extra-30', 'mode' => TreatmentMode::DoubleTherapist, 'duration' => 30, 'price' => 100000],
            ['service' => 'nezuko-vip', 'code' => 'nezuko-vip-regular-extra-60', 'mode' => TreatmentMode::Regular, 'duration' => 60, 'price' => 100000],
            ['service' => 'nezuko-vip', 'code' => 'nezuko-vip-double-extra-30', 'mode' => TreatmentMode::DoubleTherapist, 'duration' => 30, 'price' => 100000],
        ];

        $this->removeConflictingExtensionChoices($services, $rows);

        foreach ($rows as $index => $row) {
            ServiceExtension::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'service_id' => $services[$row['service']]->id,
                    'treatment_mode' => $row['mode'],
                    'duration_minutes' => $row['duration'],
                    'price_idr' => $row['price'],
                    'is_active' => true,
                    'display_order' => $index + 1,
                ],
            );
        }

        ServiceExtension::query()
            ->whereIn('service_id', collect($services)->pluck('id'))
            ->whereNotIn('code', collect($rows)->pluck('code'))
            ->update(['is_active' => false]);
    }

    /** @param array<string, Service> $services @param list<array<string, mixed>> $rows */
    private function removeConflictingVariantChoices(array $services, array $rows): void
    {
        foreach ($rows as $row) {
            ServiceVariant::query()
                ->where('service_id', $services[$row['service']]->id)
                ->where('treatment_mode', $row['mode'])
                ->where('duration_minutes', $row['duration'])
                ->where('code', '!=', $row['code'])
                ->delete();
        }
    }

    /** @param array<string, Service> $services @param list<array<string, mixed>> $rows */
    private function removeConflictingExtensionChoices(array $services, array $rows): void
    {
        foreach ($rows as $row) {
            ServiceExtension::query()
                ->where('service_id', $services[$row['service']]->id)
                ->where('treatment_mode', $row['mode'])
                ->where('duration_minutes', $row['duration'])
                ->where('code', '!=', $row['code'])
                ->delete();
        }
    }

    /** @return list<string> */
    private function placeholderSlugs(): array
    {
        return [
            'javanese-massage', 'aromatherapy-massage', 'hot-stone-massage', 'deep-tissue-massage',
            'brightening-facial', 'antiaging-facial', 'acne-facial', 'body-scrub', 'body-wrap',
            'foot-reflexology', 'serenity-package', 'couple-package',
        ];
    }
}
