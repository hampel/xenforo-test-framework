<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use PHPUnit\Framework\ExpectationFailedException;
use XF\PreEscaped;

/**
 * `<xf:sidenav>` and `<xf:sidebar>` compile to a write into the templater rather than to markup,
 * so what they produce is in neither the rendered HTML nor the page parameters. The account pages
 * build their whole sidebar that way.
 */
class NavSurfaceTest extends TestCase
{
	use UsesDatabaseTransactions;

	public function test_sidenav_markup_is_absent_from_the_html_and_present_in_the_accessor()
	{
		$this->fakesErrors();
		$name = $this->givenATemplateContributing('sidenav', 'zznav_marker');

		$html = $this->renderTemplate($name);

		// the positive half: the render did produce its own markup, so an empty render is not
		// what makes the negative assertion below pass
		$this->assertSee($html, 'body of the template');
		$this->assertFalse(strpos($html, 'zznav_marker') !== false, 'the HTML should not carry it');
		$this->assertTrue(strpos($this->sideNavHtml(), 'zznav_marker') !== false);
	}

	public function test_sidebar_markup_goes_the_same_way()
	{
		$this->fakesErrors();
		$name = $this->givenATemplateContributing('sidebar', 'zzbar_marker');

		$html = $this->renderTemplate($name);

		$this->assertSee($html, 'body of the template');
		$this->assertFalse(strpos($html, 'zzbar_marker') !== false, 'the HTML should not carry it');
		$this->assertTrue(strpos($this->sidebarHtml(), 'zzbar_marker') !== false);
	}

	public function test_it_accumulates_across_renders_in_one_test()
	{
		$this->fakesErrors();
		$first = $this->givenATemplateContributing('sidenav', 'zznav_first');
		$second = $this->givenATemplateContributing('sidenav', 'zznav_second');

		$this->renderTemplate($first);
		$this->renderTemplate($second);

		// both renders' blocks are there, which is what the page parameters do as well - a test
		// rendering twice is asserting against the pair
		$nav = $this->sideNavHtml();
		$this->assertTrue(strpos($nav, 'zznav_first') !== false, 'the first render should still be there');
		$this->assertTrue(strpos($nav, 'zznav_second') !== false);
	}

	public function test_an_unresolved_phrase_in_the_side_navigation_is_reported()
	{
		$this->fakesErrors();
		$name = $this->givenATemplateContributing('sidenav', 'zzphrase_only_in_the_nav');

		$html = $this->renderTemplate($name);

		// it is nowhere in the HTML, so the assertion given the HTML could only find it by
		// reading the surface the render wrote it to
		$this->assertFalse(strpos($html, 'zzphrase_only_in_the_nav') !== false);

		try
		{
			$this->assertNoUnresolvedPhrases($html, 'zzphrase_');
			$this->fail('a key in the side navigation should have been reported');
		}
		catch (ExpectationFailedException $e)
		{
			$reported = print_r($e->getComparisonFailure()->getActual(), true);

			$this->assertStringContainsString('zzphrase_only_in_the_nav', $reported);
			$this->assertStringContainsString('side navigation', $reported, 'the failure names the surface');
		}
	}

	public function test_the_blocks_are_pre_escaped_objects_rather_than_strings()
	{
		$this->fakesErrors();
		$this->renderTemplate($this->givenATemplateContributing('sidenav', 'zznav_typed'));

		// which is why the accessors cast rather than concatenating the values as they come. If
		// XenForo ever stores plain strings here, this fails and the cast becomes redundant
		foreach ($this->app()->templater()->getSideNavHtml() AS $block)
		{
			$this->assertInstanceOf(PreEscaped::class, $block);
		}

		$this->assertSee($this->sideNavHtml(), 'zznav_typed');
	}

	/**
	 * A template of this test's own whose only contribution to the given surface carries the
	 * marker, plus body markup of its own so a negative assertion is not passing on nothing.
	 *
	 * @param string $tag - sidenav or sidebar
	 * @param string $marker
	 *
	 * @return string - the type:title to render
	 */
	private function givenATemplateContributing($tag, $marker)
	{
		$title = 'zzsurface_' . substr(md5(uniqid('', true)), 0, 8);

		$this->createEntity('XF:Template', [
			'style_id' => 0,
			'type' => 'public',
			'title' => $title,
			'template' => "<xf:$tag><div>$marker</div></xf:$tag><p>body of the template</p>",
			'addon_id' => '',
		]);

		return 'public:' . $title;
	}
}
