<?php

namespace App\Helpers\Emails;

use Latte\Engine;
use Latte\Essential\Filters;
use League\CommonMark\CommonMarkConverter;

/**
 * Factory for latte engine which can be used in email senders.
 * Constructs an instance of EmailLatteWrapper and inject latte engine inside.
 */
class EmailLatteFactory
{
    /**
     * Create latte engine for email templates with helper filters.
     * @return EmailLatteWrapper
     */
    public static function latte(): EmailLatteWrapper
    {
        $latte = new Engine();
        $latte->setTempDirectory(__DIR__ . "/../../../temp");

        // extra tag(s) for emails
        //$latte->addMacro("emailSubject", EmailMacros::install($latte->getCompiler()));
        $latte->addExtension(new EmailLatteExtension());

        // filters
        $latte->addFilter(
            "localizedDate",
            function ($date, $locale) {
                if ($locale === EmailLocalizationHelper::CZECH_LOCALE) {
                    return Filters::date($date, 'j.n.Y H:i');
                }

                return Filters::date($date, 'n/j/Y H:i');
            }
        );

        $latte->addFilter(
            "relativeDateTime",
            function ($dateDiff, $locale) {
                return EmailLocalizationHelper::getDateIntervalLocalizedString($dateDiff, $locale);
            }
        );

        $latte->addFilter(
            "markdown",
            function ($markdown) {
                $converter = new CommonMarkConverter([
                    'html_input' => 'strip',
                    'allow_unsafe_links' => false,
                ]);

                $html = $converter->convert($markdown);
                return "<div class=\"markdown\">$html</div>";
            }
        );

        // The one call to action a message has, as a button rather than a pasted address.
        // A function rather than an included template because the templates sit at four different
        // depths under this directory, and a relative include path cannot be right for all of
        // them. Fully inline styles: this is the element that must survive a client that drops
        // the <style> block, and a button that degrades into invisible text is worse than no
        // button. The wrapping table is the "bulletproof button" shape Outlook's Word renderer
        // needs -- it ignores padding on an <a>, but honours it on a <td>.
        $latte->addFunction(
            "button",
            function (string $url, string $label): string {
                $font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif";
                $url = htmlspecialchars($url, ENT_QUOTES, "UTF-8");
                $label = htmlspecialchars($label, ENT_QUOTES, "UTF-8");
                return <<<HTML
                    <table role="presentation" class="btn" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0;">
                        <tr>
                            <td align="center" style="border-radius:8px;background:#016BAB;">
                                <a href="$url" style="display:inline-block;padding:13px 24px;font-family:$font;font-size:15px;font-weight:600;line-height:1.2;color:#ffffff;text-decoration:none;border-radius:8px;">$label</a>
                            </td>
                        </tr>
                    </table>
                    HTML;
            }
        );

        // The same address as plain text, for the messages where the link *is* the message -- a
        // password reset, an e-mail verification. Those are the ones a reader retries by hand when
        // the button does not work, and the ones where a security-minded reader wants to see where
        // they are being sent before they go.
        $latte->addFunction(
            "linkFallback",
            function (string $url, string $intro): string {
                $font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif";
                $safe = htmlspecialchars($url, ENT_QUOTES, "UTF-8");
                $intro = htmlspecialchars($intro, ENT_QUOTES, "UTF-8");
                return <<<HTML
                    <p class="quiet" style="font-family:$font;font-size:14px;line-height:1.6;color:#5b6670;">
                        $intro<br>
                        <a href="$safe" style="color:#016BAB;word-break:break-all;">$safe</a>
                    </p>
                    HTML;
            }
        );

        return new EmailLatteWrapper($latte);
    }
}
