<?php

namespace Tests\Feature\Site;

use App\Mail\ContactMessageMail;
use App\Models\Amenity;
use App\Models\CancellationPolicy;
use App\Models\Faq;
use App\Models\Page;
use App\Models\PropertyProfile;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Review;
use App\Models\RoomType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    private PropertyProfile $profile;

    private RoomType $villa;

    private RatePlan $ratePlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->profile = PropertyProfile::factory()->create([
            'name' => 'Casa de Mantra',
            'currency' => 'IDR',
            'whatsapp' => '+62 812-3456-7890',
            'email' => 'stay@example.com',
            'address' => 'Jalan Pantai, Bali',
            'checkin_time' => '14:00:00',
            'checkout_time' => '12:00:00',
            'seo_settings' => [
                'meta_title' => 'Casa de Mantra — Private Villa',
                'meta_description' => 'A private villa for slow mornings and long afternoons by the pool.',
            ],
        ]);

        $policy = CancellationPolicy::factory()->create([
            'name' => CancellationPolicy::NON_REFUNDABLE,
            'description' => 'No refunds are available after a booking is confirmed.',
        ]);

        $this->villa = RoomType::factory()->published()->create([
            'name' => 'Villa Aruna',
            'slug' => 'villa-aruna',
            'description' => 'A two-bedroom villa with a private pool and a quiet garden.',
            'max_adults' => 4,
            'max_children' => 2,
            'bedrooms' => 2,
            'bathrooms' => 2,
            'size_m2' => 180,
        ]);

        $this->villa->amenities()->attach(
            Amenity::factory()->create(['name' => 'Private swimming pool', 'category' => 'outdoor']),
        );

        $this->villa->media()->create([
            'collection_name' => RoomType::GALLERY,
            'name' => 'villa-aruna',
            'file_name' => 'villa-aruna.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'size' => 1024,
            'manipulations' => [],
            'custom_properties' => ['alt' => 'Villa Aruna pool and garden'],
            'generated_conversions' => [],
            'responsive_images' => [],
            'order_column' => 1,
        ]);

        $this->ratePlan = RatePlan::factory()->for($this->villa)->create([
            'cancellation_policy_id' => $policy->id,
            'is_active' => true,
            'min_stay' => 2,
        ]);

        RateRule::factory()->for($this->ratePlan)->create([
            'price_per_night' => 2500000,
        ]);
    }

    public function test_home_page_lists_published_villas_with_prices_reviews_and_faqs(): void
    {
        RoomType::factory()->create(['name' => 'Hidden Villa', 'slug' => 'hidden-villa']);
        Review::factory()->create(['guest_name' => 'Kimul', 'comment' => 'A wonderful and restful stay.']);
        Faq::factory()->create(['question' => 'Is breakfast included?']);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('A private villa,')
            ->assertSee('Villa Aruna')
            ->assertDontSee('Hidden Villa')
            ->assertSee('Rp2.500.000')
            ->assertSee('A wonderful and restful stay.')
            ->assertSee('Is breakfast included?')
            ->assertSee('https://wa.me/6281234567890', false);
    }

    public function test_home_page_renders_seo_meta_open_graph_and_lodging_schema(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('<title>Casa de Mantra — Private Villa</title>', false)
            ->assertSee('<meta name="description" content="A private villa for slow mornings and long afternoons by the pool.">', false)
            ->assertSee('<meta property="og:title" content="Casa de Mantra — Private Villa">', false)
            ->assertSee('property="og:image"', false)
            ->assertSee('"@type":"VacationRental"', false)
            ->assertSee('"name":"Private swimming pool"', false);
    }

    public function test_whatsapp_button_is_hidden_when_the_profile_has_no_number(): void
    {
        $this->profile->update(['whatsapp' => null]);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('https://wa.me/', false)
            ->assertDontSee('Chat with us on WhatsApp');
    }

    public function test_villa_detail_shows_content_amenities_policy_and_price(): void
    {
        $this->get(route('villas.show', $this->villa->slug))
            ->assertOk()
            ->assertSee('Villa Aruna')
            ->assertSee('A two-bedroom villa with a private pool and a quiet garden.')
            ->assertSee('Private swimming pool')
            ->assertSee('Rp2.500.000')
            ->assertSee('Non-Refundable policy')
            ->assertSee('No refunds are available after a booking is confirmed.')
            ->assertSee('Request this villa');
    }

    public function test_unpublished_villa_is_not_publicly_visible(): void
    {
        $hidden = RoomType::factory()->create(['name' => 'Hidden Villa', 'slug' => 'hidden-villa']);

        $this->get(route('villas.index'))
            ->assertOk()
            ->assertSee('Villa Aruna')
            ->assertDontSee('Hidden Villa');

        $this->get(route('villas.show', $hidden->slug))->assertNotFound();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function contentPages(): array
    {
        return [
            'villa index' => ['/villa'],
            'gallery' => ['/gallery'],
            'location' => ['/location'],
            'about' => ['/about'],
            'contact' => ['/contact'],
            'faq' => ['/faq'],
            'policies index' => ['/policies'],
        ];
    }

    #[DataProvider('contentPages')]
    public function test_content_pages_render(string $url): void
    {
        $this->get($url)->assertOk();
    }

    public function test_about_page_uses_the_published_page_content_when_present(): void
    {
        Page::factory()->create([
            'slug' => 'about',
            'title' => 'Our story',
            'body' => '<p>The villa has been in the family for three generations.</p>',
            'is_published' => true,
        ]);

        $this->get(route('about'))
            ->assertOk()
            ->assertSee('Our story')
            ->assertSee('The villa has been in the family for three generations.', false);
    }

    public function test_policy_pages_list_and_show_only_published_pages(): void
    {
        $published = Page::factory()->create([
            'slug' => 'privacy-policy',
            'title' => 'Privacy Policy',
            'body' => '<p>We only use your details to answer your enquiry.</p>',
            'is_published' => true,
        ]);
        $hidden = Page::factory()->unpublished()->create([
            'slug' => 'draft-policy',
            'title' => 'Draft Policy',
        ]);

        $this->get(route('policies.index'))
            ->assertOk()
            ->assertSee('Privacy Policy')
            ->assertDontSee('Draft Policy');

        $this->get(route('policies.show', $published->slug))
            ->assertOk()
            ->assertSee('Privacy Policy')
            ->assertSee('We only use your details to answer your enquiry.', false);

        $this->get(route('policies.show', $hidden->slug))->assertNotFound();
    }

    public function test_faq_page_renders_faq_structured_data(): void
    {
        Faq::factory()->create(['question' => 'Is parking available?', 'answer' => 'Yes, on site.']);

        $this->get(route('faq'))
            ->assertOk()
            ->assertSee('Is parking available?')
            ->assertSee('"@type":"FAQPage"', false)
            ->assertSee('"text":"Yes, on site."', false);
    }

    public function test_sitemap_lists_public_urls_and_published_pages(): void
    {
        $page = Page::factory()->create(['slug' => 'terms', 'title' => 'Terms', 'is_published' => true]);
        $hidden = Page::factory()->unpublished()->create(['slug' => 'draft']);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('content-type', 'application/xml; charset=UTF-8')
            ->assertSee(route('home'), false)
            ->assertSee(route('villas.show', $this->villa->slug), false)
            ->assertSee(route('policies.show', $page->slug), false)
            ->assertDontSee(route('policies.show', $hidden->slug), false);
    }

    public function test_robots_points_to_the_sitemap_and_blocks_admin(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('content-type', 'text/plain; charset=UTF-8')
            ->assertSee('Disallow: /admin')
            ->assertSee('Sitemap: '.route('sitemap'));
    }

    public function test_contact_form_sends_an_email_to_the_property(): void
    {
        Mail::fake();

        $this->post(route('contact.store'), [
            'name' => 'Jane Guest',
            'email' => 'jane@example.com',
            'phone' => '+62 811 222 333',
            'villa' => 'villa-aruna',
            'message' => 'We would like to stay for five nights in December.',
        ])
            ->assertRedirect(route('contact'))
            ->assertSessionHas('status');

        Mail::assertSent(ContactMessageMail::class, function (ContactMessageMail $mail): bool {
            return $mail->hasTo('stay@example.com')
                && $mail->senderName === 'Jane Guest'
                && $mail->senderEmail === 'jane@example.com'
                && $mail->villa === 'villa-aruna';
        });
    }

    public function test_contact_form_validates_input_before_sending(): void
    {
        Mail::fake();

        $this->post(route('contact.store'), [
            'name' => '',
            'email' => 'not-an-email',
            'message' => '',
        ])->assertSessionHasErrors(['name', 'email', 'message']);

        Mail::assertNothingSent();
    }
}
