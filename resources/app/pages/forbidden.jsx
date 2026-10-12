import { Button, Page, Section } from '../components/ui.jsx';
import { appUrl, route } from '../router.jsx';

/** Shown when the signed-in staff member's role doesn't allow a page, or their access was removed. */
export default function Forbidden({ message, removed }) {
  return (
    <Page heading={removed ? 'Access removed' : 'Permission needed'}>
      <Section>
        {removed ? (
          <p>Your access to Store Intelligence for this store was removed. Ask a store owner to restore it in Settings → Users &amp; roles.</p>
        ) : (
          <>
            <p>{message || 'You don\'t have permission to perform this action.'}</p>
            <p>Ask a store owner or admin to change your role in Settings → Users &amp; roles.</p>
            <Button href={appUrl(route('app.dashboard'))}>Back to Home</Button>
          </>
        )}
      </Section>
    </Page>
  );
}
