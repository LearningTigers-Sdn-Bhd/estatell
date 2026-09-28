import { Form, Head } from '@inertiajs/react';
import PropertyBasicsController from '@/actions/App/Http/Controllers/Onboarding/PropertyBasicsController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { edit as basicsEdit } from '@/routes/onboarding/basics';

type Option = { value: string; label: string };

export default function Basics({
    basics,
    propertyTypes,
}: {
    basics: App.Data.Property.PropertyBasicsData;
    propertyTypes: Option[];
}) {
    return (
        <>
            <Head title="Property basics" />
            <div className="flex flex-col gap-6 p-4">
                <Heading
                    title="Property basics"
                    description="Tell us about your place. We will prepare its settings for Malaysia."
                />
                <Form
                    {...PropertyBasicsController.update.form()}
                    onError={(errors) => {
                        const first = Object.keys(errors)[0];
                        if (first) document.getElementById(first)?.focus();
                    }}
                >
                    {({ processing, errors }) => (
                        <Card>
                            <CardHeader>
                                <CardTitle>Place and address</CardTitle>
                                <CardDescription>
                                    Country: Malaysia
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="grid gap-5 md:grid-cols-2">
                                <Field className="md:col-span-2">
                                    <FieldLabel htmlFor="name">
                                        Property name
                                    </FieldLabel>
                                    <Input
                                        id="name"
                                        name="name"
                                        defaultValue={basics.name}
                                        aria-invalid={!!errors.name}
                                    />
                                    <FieldError>{errors.name}</FieldError>
                                </Field>
                                <Field>
                                    <FieldLabel htmlFor="type">
                                        Property type
                                    </FieldLabel>
                                    <Select
                                        name="type"
                                        items={propertyTypes}
                                        defaultValue={basics.type ?? undefined}
                                    >
                                        <SelectTrigger
                                            id="type"
                                            aria-invalid={!!errors.type}
                                        >
                                            <SelectValue placeholder="Choose a type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectGroup>
                                                {propertyTypes.map((option) => (
                                                    <SelectItem
                                                        key={option.value}
                                                        value={option.value}
                                                    >
                                                        {option.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectGroup>
                                        </SelectContent>
                                    </Select>
                                    <FieldError>{errors.type}</FieldError>
                                </Field>
                                <Field>
                                    <FieldLabel htmlFor="city">City</FieldLabel>
                                    <Input
                                        id="city"
                                        name="city"
                                        defaultValue={basics.city ?? ''}
                                        aria-invalid={!!errors.city}
                                    />
                                    <FieldError>{errors.city}</FieldError>
                                </Field>
                                <Field className="md:col-span-2">
                                    <FieldLabel htmlFor="address_line1">
                                        Address line 1
                                    </FieldLabel>
                                    <Input
                                        id="address_line1"
                                        name="address_line1"
                                        defaultValue={basics.addressLine1 ?? ''}
                                        aria-invalid={!!errors.address_line1}
                                    />
                                    <FieldError>
                                        {errors.address_line1}
                                    </FieldError>
                                </Field>
                                <Field className="md:col-span-2">
                                    <FieldLabel htmlFor="address_line2">
                                        Address line 2 (optional)
                                    </FieldLabel>
                                    <Input
                                        id="address_line2"
                                        name="address_line2"
                                        defaultValue={basics.addressLine2 ?? ''}
                                        aria-invalid={!!errors.address_line2}
                                    />
                                    <FieldError>
                                        {errors.address_line2}
                                    </FieldError>
                                </Field>
                                <Field>
                                    <FieldLabel htmlFor="state_code">
                                        State (optional)
                                    </FieldLabel>
                                    <Input
                                        id="state_code"
                                        name="state_code"
                                        defaultValue={basics.stateCode ?? ''}
                                        aria-invalid={!!errors.state_code}
                                    />
                                    <FieldError>{errors.state_code}</FieldError>
                                </Field>
                                <Field>
                                    <FieldLabel htmlFor="postal_code">
                                        Postcode (optional)
                                    </FieldLabel>
                                    <Input
                                        id="postal_code"
                                        name="postal_code"
                                        defaultValue={basics.postalCode ?? ''}
                                        aria-invalid={!!errors.postal_code}
                                    />
                                    <FieldError>
                                        {errors.postal_code}
                                    </FieldError>
                                </Field>
                            </CardContent>
                            <CardFooter className="justify-end">
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    focusableWhenDisabled
                                >
                                    {processing && <Spinner />}Save basics
                                </Button>
                            </CardFooter>
                        </Card>
                    )}
                </Form>
            </div>
        </>
    );
}

Basics.layout = {
    breadcrumbs: [{ title: 'Property basics', href: basicsEdit() }],
};
