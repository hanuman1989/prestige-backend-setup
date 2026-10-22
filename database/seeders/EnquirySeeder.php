<?php

namespace Database\Seeders;

use App\Models\Enquiry;
use Illuminate\Database\Seeder;

class EnquirySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Enquiry::factory()->count(10)->create();
    }
}
