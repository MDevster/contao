<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao;

/**
 * Front end module "article list".
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
class ModuleArticlenav extends Module
{
	/**
	 * Template
	 * @var string
	 */
	protected $strTemplate = 'mod_articlenav';

	/**
	 * Articles
	 * @var ArticleModel[]
	 */
	protected $objArticles;

	/**
	 * Do not display the module if there are no articles
	 *
	 * @return string
	 */
	public function generate()
	{
		$request = System::getContainer()->get('request_stack')->getCurrentRequest();

		if ($request && System::getContainer()->get('contao.routing.scope_matcher')->isBackendRequest($request))
		{
			$objTemplate = new BackendTemplate('be_wildcard');
			$objTemplate->wildcard = '### ' . $GLOBALS['TL_LANG']['FMD']['articlenav'][0] . ' ###';
			$objTemplate->title = $this->headline;
			$objTemplate->id = $this->id;
			$objTemplate->link = $this->name;
			$objTemplate->href = 'contao/main.php?do=themes&amp;table=tl_module&amp;act=edit&amp;id=' . $this->id;

			return $objTemplate->parse();
		}

		/** @var PageModel $objPage */
		global $objPage;

		$this->objArticles = ArticleModel::findPublishedWithTeaserByPidAndColumn($objPage->id, $this->strColumn);

		// Return if there are no articles
		if ($this->objArticles === null)
		{
			return '';
		}

		// Redirect to the first article if no article is selected
		if (!Input::get('articles'))
		{
			if (!$this->loadFirst)
			{
				return '';
			}

			/** @var ArticleModel $objArticle */
			$objArticle = $this->objArticles->current();
			$strAlias = $objArticle->alias ?: $objArticle->id;

			$this->redirect($objPage->getFrontendUrl('/articles/' . $strAlias));
		}

		return parent::generate();
	}

	/**
	 * Generate the module
	 */
	protected function compile()
	{
		/** @var PageModel $objPage */
		global $objPage;

		$intActive = null;
		$articles = array();
		$intCount = 1;

		foreach ($this->objArticles as $objArticle)
		{
			$strAlias = $objArticle->alias ?: $objArticle->id;

			// Active article
			if (Input::get('articles') == $strAlias)
			{
				$articles[] = array
				(
					'isActive' => true,
					'href' => $objPage->getFrontendUrl('/articles/' . $strAlias),
					'title' => StringUtil::specialchars($objArticle->title, true),
					'link' => $intCount
				);

				$intActive = ($intCount - 1);
			}

			// Inactive article
			else
			{
				$articles[] = array
				(
					'isActive' => false,
					'href' => $objPage->getFrontendUrl('/articles/' . $strAlias),
					'title' => StringUtil::specialchars($objArticle->title, true),
					'link' => $intCount
				);
			}

			++$intCount;
		}

		$this->Template->articles = $articles;
		$total = \count($articles);

		// Link to first element
		if ($intActive > 1)
		{
			$this->Template->first = array
			(
				'href' => $articles[0]['href'],
				'title' => $articles[0]['title'],
				'link' => $GLOBALS['TL_LANG']['MSC']['first']
			);
		}

		$key = $intActive - 1;

		// Link to previous element
		if ($intCount > 1 && $key >= 0)
		{
			$this->Template->previous = array
			(
				'href' => $articles[$key]['href'],
				'title' => $articles[$key]['title'],
				'link' => $GLOBALS['TL_LANG']['MSC']['previous']
			);
		}

		$key = $intActive + 1;

		// Link to next element
		if ($intCount > 1 && $key < $total)
		{
			$this->Template->next = array
			(
				'href' => $articles[$key]['href'],
				'title' => $articles[$key]['title'],
				'link' => $GLOBALS['TL_LANG']['MSC']['next']
			);
		}

		$key = $total - 1;

		// Link to last element
		if ($intCount > 1 && $intActive < ($key - 1))
		{
			$this->Template->last = array
			(
				'href' => $articles[$key]['href'],
				'title' => $articles[$key]['title'],
				'link' => $GLOBALS['TL_LANG']['MSC']['last']
			);
		}
	}
}

class_alias(ModuleArticlenav::class, 'ModuleArticlenav');
