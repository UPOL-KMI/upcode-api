<?php

namespace App\Helpers\Emails;

use Latte;
use Latte\Compiler\PrintContext;
use Latte\Compiler\Tag;
use Latte\Compiler\Nodes\Php\Scalar\StringNode;
use Latte\Compiler\Nodes\AuxiliaryNode;
use Generator;

/**
 * Extension class that is registered into latte to handle email subjects.
 * (introduces {emailSubject} tag which captures the subject of rendered email templates)
 *
 * The captured subject is **not** echoed back into the body. It used to be, which meant every
 * message opened with its own subject as a bare line of text, directly above the heading that
 * said the same thing again -- and below the subject line the mail client had already shown.
 * The subject is returned to the caller through setSubject(); the body has no use for it.
 */
class EmailLatteExtension extends Latte\Extension
{
    /**
     * Holds last scraped subject from email template.
     * @var string|null
     */
    private static $subject = null;

    /**
     * Used both by the extension and to re-set the subject before rendering.
     * @param string|null $subject
     */
    public static function setSubject(?string $subject): void
    {
        self::$subject = $subject;
    }

    /**
     * Get last stored subject.
     * @return string|null
     */
    public static function getLastSubject(): ?string
    {
        return self::$subject;
    }

    /*
     * Extension stuff
     */

    public function getTags(): array
    {
        // registers a new tag {emailSubject}
        return [
            'emailSubject' => [$this, 'createEmailSubject'],
        ];
    }

    public function createEmailSubject(Tag $tag): Generator
    {
        [$content, $endTag] = yield; // wait for the closing tag and get the contents

        // auxiliary node creates PHP content based on given lambda (print) function
        return new AuxiliaryNode(
            function (PrintContext $context) use ($content) {
                return $context->format(
                    <<<'CODE'
                        ob_start(fn() => '');
                        try {
                            (function () { extract(func_get_arg(0));
                                %node
                            })(get_defined_vars());
                        } finally {
                            $ʟ_recodex_email_subject = ob_get_clean();
                            \App\Helpers\Emails\EmailLatteExtension::setSubject($ʟ_recodex_email_subject);
                        }
                        CODE,
                    $content,
                );
            }
        );
    }
}
