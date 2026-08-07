import { Card, CopyBox } from './bits';
import { DocAction } from './types';

/**
 * What a reseller forwards to their customer's developer.
 *
 * Written as prose with worked examples rather than generated from the code,
 * because the useful parts — that this is the standard SMM API, that an error
 * arrives with a 200, that quantities are priced per 1,000 — are exactly the
 * parts a schema dump leaves out.
 */
export function DocsTab({ actions, endpoint }: { actions: DocAction[]; endpoint: string }) {
    return (
        <div className="space-y-6">
            <Card
                title="How it works"
                description="This is the standard SMM panel API. If your customer already has code for another panel, changing the URL and the key is usually all it takes."
            >
                <dl className="grid gap-4 sm:grid-cols-2">
                    <Fact term="Endpoint">
                        <code className="font-mono text-sm">{endpoint}</code>
                    </Fact>
                    <Fact term="Method">
                        <code className="font-mono text-sm">POST</code>, form-encoded
                    </Fact>
                    <Fact term="Authentication">
                        A <code className="font-mono">key</code> field in the body
                    </Fact>
                    <Fact term="Errors">
                        Answered with <code className="font-mono">200</code> and an{' '}
                        <code className="font-mono">error</code> field — check the body, not the
                        status code
                    </Fact>
                    <Fact term="Prices">Quoted per 1,000 units, as on your services screen</Fact>
                    <Fact term="Balance">
                        The customer&rsquo;s own wallet — the same one your bot spends
                    </Fact>
                </dl>
            </Card>

            <Card
                title="A first call"
                description="Paste this into a terminal, with a real key, to check everything is wired up."
            >
                <CopyBox
                    value={`curl -X POST ${endpoint} -d "key=YOUR_KEY" -d "action=balance"`}
                    label="example request"
                />
            </Card>

            <Card title="Actions" description="Every action takes key and action, plus what is listed here.">
                <ul className="space-y-6">
                    {actions.map((action, index) => (
                        <li key={`${action.action}-${index}`} className="space-y-3">
                            <div>
                                <code className="rounded bg-muted px-2 py-1 font-mono text-sm font-semibold">
                                    action={action.action}
                                </code>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    {action.summary}
                                </p>
                            </div>

                            {action.params.length > 0 && (
                                <dl className="grid gap-1.5 border-l-2 border-border pl-4 text-sm">
                                    {action.params.map((param) => (
                                        <div key={param.name} className="flex flex-wrap gap-2">
                                            <dt className="font-mono text-foreground">
                                                {param.name}
                                            </dt>
                                            <dd className="text-muted-foreground">{param.note}</dd>
                                        </div>
                                    ))}
                                </dl>
                            )}

                            <div>
                                <p className="mb-1.5 text-xs font-medium text-muted-foreground">
                                    Answers with
                                </p>
                                <code className="scroll-slim block overflow-x-auto rounded-lg border border-border bg-muted/40 px-3 py-2 font-mono text-xs whitespace-nowrap">
                                    {action.example}
                                </code>
                            </div>
                        </li>
                    ))}
                </ul>
            </Card>
        </div>
    );
}

function Fact({ term, children }: { term: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {term}
            </dt>
            <dd className="mt-1 text-sm">{children}</dd>
        </div>
    );
}
