<?php

namespace Base\Restaurant\Form;

use Base\Restaurant\Model\TakeHomeOrder;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * An order of the take-home shop (/traiteur/commander), as its page's form
 * posts it: the dishes and how many (lines[<dish id>]), how it leaves and
 * when, who. A root form without a name (createNamed('')): its fields keep
 * the page's names - email, lines[12], day. Guarded as glitchr/omnibase
 * guards a public form (its option `guard`, action "takehome": a trap, the
 * time it takes, the lists, the captcha when the site has glitchr/omnishield),
 * in place of the page's own trap. Its fields are validated as
 * Model\TakeHomeOrder's constraints say.
 *
 * The page writes the fields by hand (it works without JavaScript, the
 * dishes being cards); it prints the guard's own from the form's view.
 */
class TakeHomeOrderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('lines', CollectionType::class, ['entry_type' => IntegerType::class, 'allow_add' => true, 'allow_delete' => true])
            ->add('mode', TextType::class, ['empty_data' => ''])
            ->add('day', TextType::class, ['empty_data' => ''])
            ->add('slot', TextType::class, ['required' => false])
            ->add('address', TextType::class, ['required' => false])
            ->add('postcode', TextType::class, ['required' => false])
            ->add('city', TextType::class, ['required' => false])
            ->add('name', TextType::class, ['empty_data' => ''])
            ->add('phone', TextType::class, ['empty_data' => ''])
            ->add('email', EmailType::class, ['empty_data' => ''])
            ->add('note', TextType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TakeHomeOrder::class,
            'csrf_token_id' => 'restaurant_takehome',
            // Fields a page leaves out (no address for a collection) stay as they are.
            'allow_extra_fields' => true,
            'guard' => ['action' => 'takehome'],
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'restaurant_takehome';
    }
}
