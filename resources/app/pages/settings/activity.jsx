import { ago, Button, dateTime, Empty, Page, Section, Stack } from '../../components/ui.jsx';
import { appUrl, route } from '../../router.jsx';

export default function Activity({ logs }) {
  return (
    <Page heading="Activity" subtitle="Changes made in Store Intelligence for this store: who, what and when.">
      <Section>
        {!logs.data.length ? <Empty icon="list" title="No activity yet" text="Changes to settings, costs and users show up here." /> : (
          <>
            <div className="oo-scroll">
              <table className="oo-table stack">
                <thead><tr><th>When</th><th>Who</th><th>What</th><th>Details</th><th>Request ID</th></tr></thead>
                <tbody>
                  {logs.data.map((log) => (
                    <tr key={log.id}>
                      <td title={dateTime(log.created_at)}>{ago(log.created_at)}</td>
                      <td data-label="Who">{log.who}</td>
                      <td>{log.what}</td>
                      <td className="oo-muted oo-small">{log.details}</td>
                      <td>{log.request_id && <span className="oo-code">{log.request_id}</span>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Stack direction="inline" paddingBlockStart="base">
              {logs.page > 1 && <Button href={appUrl(route('app.settings.activity', { page: logs.page - 1 }))}>Newer</Button>}
              {logs.page < logs.last_page && <Button href={appUrl(route('app.settings.activity', { page: logs.page + 1 }))}>Older</Button>}
            </Stack>
          </>
        )}
      </Section>
    </Page>
  );
}
