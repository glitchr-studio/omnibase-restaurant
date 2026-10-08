<?php

namespace Base\Restaurant\Tests;

use Base\Restaurant\Reviews\RestaurantReviews;
use Base\Restaurant\Twig\ReviewsTwigExtension;
use Omnireview\Bridge\Twig\OmnireviewExtension;
use Omnireview\Config;
use Omnireview\GatewayFactory;
use Omnireview\GatewayInterface;
use Omnireview\Model\Capabilities;
use Omnireview\Model\Place;
use Omnireview\Model\Rating;
use Omnireview\Model\Reply;
use Omnireview\Model\Review;
use Omnireview\Model\Terms;
use Omnireview\RatingInterface;
use Omnireview\Registry;
use Omnireview\ReviewsInterface;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

/**
 * What guests say of the restaurant elsewhere (glitchr/omnireview): the
 * platforms its page shows - those configured, their gateway set up -, the
 * latest reviews over them and those not answered, the page's section by
 * the family's widget (origin, date, link, no JSON-LD) and the dashboard's
 * tile; nothing at all without a gateway. Skipped without glitchr/omnireview
 * and Twig: the platforms are stubs made here, no key is used.
 */
final class ReviewsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Registry::class) || !class_exists(OmnireviewExtension::class) || !class_exists(Environment::class)) {
            self::markTestSkipped('Needs glitchr/omnireview and twig/twig.');
        }
    }

    /** A platform kept in memory: its rating, two reviews (one answered), a reply where its owner may. */
    private static function factory(string $name, bool $owner): GatewayFactory
    {
        return new class($name, $owner) extends GatewayFactory {
            public function __construct(private readonly string $name, private readonly bool $owner)
            {
            }

            protected function populate(Config $c): void
            {
                $c->defaults(['omnireview.factory_name' => $this->name, 'omnireview.factory_title' => ucfirst($this->name), 'omnireview.required_options' => []]);
            }

            protected function build(Config $c): GatewayInterface
            {
                return new class($this->name, $this->owner) implements RatingInterface, ReviewsInterface {
                    public function __construct(private readonly string $name, private readonly bool $owner)
                    {
                    }

                    public function getName(): string { return $this->name; }

                    public function getTitle(): string { return ucfirst($this->name); }

                    public function capabilities(): Capabilities { return new Capabilities(rating: true, reviews: true, reply: $this->owner); }

                    public function terms(): Terms { return new Terms(cacheFor: 0, maxReviews: 5, attribution: ucfirst($this->name).' Maps'); }

                    public function rating(Place $place): Rating
                    {
                        return new Rating($this->name, 4.6, 128, url: 'https://'.$this->name.'.example/'.$place->id);
                    }

                    public function reviews(Place $place, int $limit = 5, ?string $language = null): array
                    {
                        $day = 'google' === $this->name ? 20 : 25;

                        return [
                            new Review($this->name, $this->name.'-1', 5, 'Le meilleur ramen <de> la ville', author: 'Camille R.', authorUrl: 'https://'.$this->name.'.example/u/1', publishedAt: new \DateTimeImmutable("2026-09-$day"), url: 'https://'.$this->name.'.example/r/1'),
                            new Review($this->name, $this->name.'-2', 3, 'Un peu d\'attente', author: 'Jordan K.', publishedAt: new \DateTimeImmutable('2026-09-0'.($day - 18)), url: 'https://'.$this->name.'.example/r/2', reply: new Reply('Merci !')),
                        ];
                    }
                };
            }
        };
    }

    private static function reviews(array $places = ['google' => 'ChIJ-nakaya', 'tripadvisor' => '1234567', 'trustpilot' => ''], array $owners = ['google' => 'accounts/1/locations/2']): RestaurantReviews
    {
        $registry = new Registry([self::factory('google', true), self::factory('trustpilot', false)], ['google' => ['factory' => 'google'], 'trustpilot' => ['factory' => 'trustpilot']]);

        return new RestaurantReviews($registry, new OmnireviewExtension($registry), $places, $owners, 3);
    }

    public function testThePlatformsShownAreThoseConfigured(): void
    {
        $places = self::reviews()->places();

        self::assertSame(['google'], array_column($places, 'gateway'), 'tripadvisor has no gateway (no key), trustpilot no ID: left out');
        self::assertSame(['ChIJ-nakaya', 'accounts/1/locations/2'], [$places[0]['place']->id, $places[0]['place']->owner]);
        self::assertSame([], (new RestaurantReviews(null, null, ['google' => 'ChIJ-nakaya']))->places(), 'without the family\'s bundle: none');
        self::assertSame([], (new ReviewsTwigExtension(null))->getFunctions()[0]->getCallable()(), 'without glitchr/omnireview: none');
    }

    public function testTheLatestReviewsAndThoseNotAnswered(): void
    {
        $reviews = self::reviews(['google' => 'ChIJ-nakaya', 'trustpilot' => 'unit-42'], ['google' => 'accounts/1/locations/2']);

        $latest = $reviews->latest(3);
        self::assertSame(['trustpilot-1', 'google-1', 'trustpilot-2'], array_map(static fn ($r) => $r['review']->id, $latest), 'newest first, over every platform');
        self::assertSame(['google-1'], array_map(static fn ($r) => $r['review']->id, $reviews->unanswered()), 'answered or not: only where the owner\'s side tells (Google with its location here)');
    }

    private function twig(): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../templates', 'Restaurant');
        $loader->addPath(\dirname((string) (new \ReflectionClass(OmnireviewExtension::class))->getFileName()).'/templates', 'Omnireview');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addFilter(new TwigFilter('trans', static fn (string $key, array $parameters = []) => $key.($parameters ? json_encode($parameters) : '')));

        return $twig;
    }

    public function testThePageShowsEachPlatformWithItsOriginDateAndLinkAndNoJsonLd(): void
    {
        $reviews = self::reviews();
        $twig = $this->twig();
        $twig->addExtension(new ReviewsTwigExtension($reviews));

        $html = $twig->render('@Restaurant/client/_reviews.html.twig', ['reviews' => $reviews->places(), 'limit' => 3]);

        self::assertStringContainsString('@restaurant.reviews.title', $html);
        self::assertStringContainsString('href="https://google.example/ChIJ-nakaya"', $html, 'the rating links to its origin');
        self::assertStringContainsString('<time datetime="2026-09-20T00:00:00+00:00">20/09/2026</time>', $html);
        self::assertStringContainsString('href="https://google.example/r/1"', $html);
        self::assertStringContainsString('Le meilleur ramen &lt;de&gt; la ville', $html, 'escaped');
        self::assertStringNotContainsString('ld+json', $html);
        self::assertStringNotContainsString('AggregateRating', $html);
    }

    public function testTheTileListsTheLatestAndThoseToAnswerAndNothingWithoutAGateway(): void
    {
        $reviews = self::reviews(['google' => 'ChIJ-nakaya', 'trustpilot' => 'unit-42']);
        $twig = $this->twig();
        $widget = new class {
            public string $label = 'Avis';
        };

        $html = $twig->render('@Restaurant/admin/widget/reviews.html.twig', ['widget' => $widget, 'shown' => true, 'latest' => $reviews->latest(5), 'unanswered' => $reviews->unanswered()]);
        self::assertStringContainsString('@restaurant.admin.widget.reviews_unanswered{&quot;count&quot;:1}', $html);
        self::assertStringContainsString('class="is-waiting"', $html);
        self::assertSame(4, substr_count($html, '<li'), 'the latest reviews');
        self::assertStringContainsString('href="https://trustpilot.example/r/1"', $html);

        self::assertSame('', trim($twig->render('@Restaurant/admin/widget/reviews.html.twig', ['widget' => $widget, 'shown' => false, 'latest' => [], 'unanswered' => []])), 'no gateway: nothing at all');
    }
}
