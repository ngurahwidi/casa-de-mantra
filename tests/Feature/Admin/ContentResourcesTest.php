<?php

namespace Tests\Feature\Admin;

use App\Enums\TaxFeeAppliesTo;
use App\Enums\TaxFeeType;
use App\Filament\Resources\Amenities\AmenityResource;
use App\Filament\Resources\Amenities\Pages\ManageAmenities;
use App\Filament\Resources\Faqs\Pages\ManageFaqs;
use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Filament\Resources\Reviews\Pages\ManageReviews;
use App\Filament\Resources\TaxFees\Pages\ManageTaxFees;
use App\Filament\Resources\TaxFees\TaxFeeResource;
use App\Models\Amenity;
use App\Models\Faq;
use App\Models\Page;
use App\Models\Review;
use App\Models\TaxFee;
use App\Models\User;
use Database\Seeders\AmenitySeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContentResourcesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function indexUrls(): array
    {
        return [
            'room types' => ['/admin/room-types'],
            'amenities' => ['/admin/amenities'],
            'pages' => ['/admin/pages'],
            'faqs' => ['/admin/faqs'],
            'reviews' => ['/admin/reviews'],
            'tax fees' => ['/admin/tax-fees'],
        ];
    }

    #[DataProvider('indexUrls')]
    public function test_index_pages_render(string $url): void
    {
        $this->get($url)->assertOk();
    }

    public function test_tax_fees_are_hidden_from_the_navigation(): void
    {
        $this->get('/admin')->assertOk();

        $labels = collect(Filament::getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->map(fn ($item) => $item->getLabel())
            ->all();

        $this->assertNotContains(TaxFeeResource::getNavigationLabel(), $labels);
        $this->assertContains(AmenityResource::getNavigationLabel(), $labels);
    }

    public function test_seeded_amenity_icons_are_selectable(): void
    {
        $this->seed(AmenitySeeder::class);

        $options = AmenityResource::iconOptions();

        foreach (Amenity::pluck('icon') as $icon) {
            $this->assertArrayHasKey($icon, $options);
        }

        Livewire::test(ManageAmenities::class)
            ->set('tableRecordsPerPage', 50)
            ->assertCanSeeTableRecords(Amenity::all());
    }

    public function test_amenity_is_created_and_names_stay_unique(): void
    {
        Amenity::factory()->create(['name' => 'Wi-Fi']);

        Livewire::test(ManageAmenities::class)
            ->callAction('create', ['name' => 'Sauna', 'icon' => 'heroicon-o-fire', 'category' => 'wellness'])
            ->assertHasNoFormErrors()
            ->callAction('create', ['name' => 'Wi-Fi'])
            ->assertHasFormErrors(['name' => 'unique']);

        $sauna = Amenity::where('name', 'Sauna')->sole();
        $this->assertSame('heroicon-o-fire', $sauna->icon);
        $this->assertSame('wellness', $sauna->category);
    }

    public function test_page_is_created_with_rich_text_body(): void
    {
        Livewire::test(CreatePage::class)
            ->fillForm(['title' => 'Cancellation Policy'])
            ->assertSchemaStateSet(['slug' => 'cancellation-policy'])
            ->fillForm(['body' => '<p>All bookings are <strong>non-refundable</strong>.</p>', 'is_published' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $page = Page::where('slug', 'cancellation-policy')->sole();
        $this->assertTrue($page->is_published);
        $this->assertStringContainsString('<strong>non-refundable</strong>', (string) $page->body);

        Livewire::test(ListPages::class)->assertCanSeeTableRecords([$page]);
    }

    public function test_page_slug_must_be_unique(): void
    {
        Page::factory()->create(['slug' => 'about']);

        Livewire::test(CreatePage::class)
            ->fillForm(['title' => 'About', 'slug' => 'about'])
            ->call('create')
            ->assertHasFormErrors(['slug' => 'unique']);
    }

    public function test_new_faq_goes_to_the_end_of_the_list(): void
    {
        Faq::factory()->create(['sort_order' => 4]);

        Livewire::test(ManageFaqs::class)
            ->callAction('create', ['question' => 'Is breakfast included?', 'answer' => 'No, the rate is room only.'])
            ->assertHasNoFormErrors();

        $this->assertSame(5, Faq::where('question', 'Is breakfast included?')->sole()->sort_order);
    }

    public function test_faqs_are_listed_in_sort_order(): void
    {
        $second = Faq::factory()->create(['sort_order' => 2]);
        $first = Faq::factory()->create(['sort_order' => 1]);

        Livewire::test(ManageFaqs::class)->assertCanSeeTableRecords([$first, $second], inOrder: true);
    }

    public function test_review_is_created_and_published_from_the_list(): void
    {
        Livewire::test(ManageReviews::class)
            ->callAction('create', ['guest_name' => 'Anna', 'rating' => 5, 'source' => 'Google', 'comment' => 'Lovely stay.'])
            ->assertHasNoFormErrors();

        $review = Review::where('guest_name', 'Anna')->sole();
        $this->assertSame(5, $review->rating);
        $this->assertFalse($review->is_published);

        Livewire::test(ManageReviews::class)
            ->call('updateTableColumnState', 'is_published', $review->getKey(), true);

        $this->assertTrue($review->fresh()->is_published);
    }

    public function test_review_rating_must_be_one_to_five(): void
    {
        Livewire::test(ManageReviews::class)
            ->callAction('create', ['guest_name' => 'Anna', 'rating' => 6])
            ->assertHasFormErrors(['rating']);

        $this->assertSame(0, Review::count());
    }

    public function test_tax_fee_is_created_inactive_by_default(): void
    {
        Livewire::test(ManageTaxFees::class)
            ->callAction('create', ['name' => 'Service charge', 'type' => TaxFeeType::Percent->value, 'value' => 10])
            ->assertHasNoFormErrors();

        $fee = TaxFee::sole();
        $this->assertSame(TaxFeeAppliesTo::PerNight, $fee->applies_to);
        $this->assertFalse($fee->is_active);
    }

    public function test_percent_tax_fee_cannot_exceed_one_hundred(): void
    {
        Livewire::test(ManageTaxFees::class)
            ->callAction('create', ['name' => 'Service charge', 'type' => TaxFeeType::Percent->value, 'value' => 150])
            ->assertHasFormErrors(['value' => 'max']);

        Livewire::test(ManageTaxFees::class)
            ->callAction('create', ['name' => 'Cleaning fee', 'type' => TaxFeeType::Fixed->value, 'value' => 150000])
            ->assertHasNoFormErrors();

        $this->assertSame(['Cleaning fee'], TaxFee::pluck('name')->all());
    }

    public function test_amenity_can_be_deleted_from_the_list(): void
    {
        $amenity = Amenity::factory()->create();

        Livewire::test(ManageAmenities::class)
            ->callAction(TestAction::make('delete')->table($amenity));

        $this->assertModelMissing($amenity);
    }
}
