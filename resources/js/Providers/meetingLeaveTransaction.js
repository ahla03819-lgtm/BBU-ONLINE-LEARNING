export function createMeetingLeaveTransaction() {
    let inFlight = null;

    return (operation) => {
        if (inFlight) return inFlight;

        const transaction = (async () => {
            await operation.cleanup();
            await operation.disconnect();

            if (!operation.returnToLobby) {
                operation.clearSession();
                return {status: 'cleared'};
            }

            const navigation = await operation.navigate();
            if (navigation.status === 'success') operation.clearSession();

            return navigation;
        })();

        inFlight = transaction.finally(() => {
            inFlight = null;
        });

        return inFlight;
    };
}
